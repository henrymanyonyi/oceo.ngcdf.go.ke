<?php

use App\Enums\CostMethod;
use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\RateUnit;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Memos\Team;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\DsaCalculator;
use App\Services\MemoDocument;
use App\Support\Money;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
    $this->editor = app(ActivityEditor::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    // 10 to 13 November 2026: 4 days, and 5 payable nights (the departure night too).
    $this->memo = departmentMemo($this->hod, ['start_date' => '2026-11-10', 'days' => 4]);
    $this->meals = CostCategory::query()->where('code', 'meals')->value('id');
});

function costOf(Activity $memo, string $code, ?ActivityParticipant $for = null): ?ActivityCost
{
    return $memo->costs()->whereHas('category', fn ($q) => $q->where('code', $code))
        ->when($for, fn ($q) => $q->where('activity_participant_id', $for->id))->first();
}

/** @param  array<string, mixed>  $data */
function external(Activity $memo, array $data): ActivityParticipant
{
    return app(ActivityEditor::class)->addExternal($memo, $data + ['external_name' => 'External Consultant', 'external_category' => ExternalCategory::Partner], ParticipantRole::Member, $memo->creator);
}

// ── Cost lines: per person or a fixed amount ─────────────────────────

it('works out a per-person cost line as the cost for one person times the number of people', function () {
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'description' => 'Lunch', 'calculation_method' => 'per_person', 'unit_rate' => '4,000', 'quantity' => 10], $this->hod);

    $line = $this->memo->costs()->where('cost_category_id', $this->meals)->sole();
    expect($line->method())->toBe(CostMethod::PerPerson)
        ->and($line->unit_rate)->toBe('4000.00')->and((int) $line->quantity)->toBe(10)
        ->and($line->estimated_amount)->toBe('40000.00')
        ->and($line->calculationLabel())->toBe('KES 4,000.00 × 10 people');
});

it('uses a fixed cost as the total for the activity, never multiplied by the number of people', function () {
    $this->editor->addStaff($this->memo, Staff::factory()->count(4)->create()->pluck('id')->all(), ParticipantRole::Member, $this->hod);

    $this->editor->savePlannedCost($this->memo->fresh(), ['cost_category_id' => $this->meals, 'description' => 'Activity lunch', 'calculation_method' => 'fixed', 'estimated_amount' => '50,000'], $this->hod);

    $line = $this->memo->costs()->where('cost_category_id', $this->meals)->sole();
    expect($line->method())->toBe(CostMethod::Fixed)->and($line->estimated_amount)->toBe('50000.00')
        ->and($line->unit_rate)->toBeNull()->and($line->quantity)->toBeNull();
});

it('adds lines of both methods into the activity total, and keeps the method when a line is edited', function () {
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'calculation_method' => 'per_person', 'unit_rate' => '4000', 'quantity' => 10], $this->hod);
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'calculation_method' => 'fixed', 'estimated_amount' => '50000'], $this->hod);
    expect($this->memo->fresh()->estimated_total)->toBe('90000.00');

    // The edit form opens on the line's own method and figures.
    $line = $this->memo->costs()->where('calculation_method', 'per_person')->sole();
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openCost', $line->id)
        ->assertSet('costMethod', 'per_person')->assertSet('costUnitRate', '4000.00')->assertSet('costPeople', '10')
        ->set('costPeople', '12')->call('saveCost')->assertHasNoErrors();

    expect($line->fresh()->estimated_amount)->toBe('48000.00')->and($this->memo->fresh()->estimated_total)->toBe('98000.00');

    // Switched to a fixed amount, the per-person figures are dropped.
    $this->editor->savePlannedCost($this->memo->fresh(), ['cost_category_id' => $this->meals, 'calculation_method' => 'fixed', 'estimated_amount' => '30000'], $this->hod, $line->fresh());
    expect($line->fresh()->method())->toBe(CostMethod::Fixed)->and($line->fresh()->unit_rate)->toBeNull()
        ->and($this->memo->fresh()->estimated_total)->toBe('80000.00');
});

it('refuses a per-person line without a cost or a number of people', function (array $data, string $message) {
    expect(fn () => $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'calculation_method' => 'per_person'] + $data, $this->hod))
        ->toThrow(ActivityWorkflowException::class, $message);
})->with([
    'no cost' => [['unit_rate' => '', 'quantity' => 10], 'cost per person'],
    'zero cost' => [['unit_rate' => '0', 'quantity' => 10], 'cost per person'],
    'no people' => [['unit_rate' => '4000', 'quantity' => 0], 'number of people'],
]);

it('validates the per-person fields on the team page', function () {
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openCost')
        ->set('costCategoryId', (string) $this->meals)->set('costMethod', 'per_person')
        ->call('saveCost')->assertHasErrors(['costUnitRate', 'costPeople'])
        ->set('costUnitRate', '4,000')->set('costPeople', '10')
        ->call('saveCost')->assertHasNoErrors();

    expect($this->memo->fresh()->estimated_total)->toBe('40000.00');
});

// ── External participants and their payments ─────────────────────────

it('pays an external participant a custom nightly rate for their payable nights, in the activity total', function () {
    $consultant = external($this->memo, ['external_designation' => 'External consultant', 'rate_source' => 'custom', 'rate_amount' => '5,000', 'rate_unit' => 'night']);

    $line = costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $consultant);
    // 10 to 13 November: 4 days, 5 nights with the departure night (the same rule as staff DSA).
    expect($line->is_computed)->toBeTrue()->and((int) $line->quantity)->toBe(5)
        ->and($line->unit_rate)->toBe('5000.00')->and($line->estimated_amount)->toBe('25000.00')
        ->and($line->description)->toBe('Custom rate, 5 nights × 5,000.00')
        ->and($consultant->fresh()->rateLabel())->toBe('KES 5,000.00 per night')
        ->and(Money::toCents($this->memo->fresh()->estimated_total))->toBe(Money::toCents('25000') + $this->memo->costs()->where('is_computed', false)->get()->sum(fn ($c) => Money::toCents($c->estimated_amount)));
});

it('pays a daily rate per day and a single payment once', function () {
    $daily = external($this->memo, ['external_name' => 'Resource person', 'rate_source' => 'custom', 'rate_amount' => '3000', 'rate_unit' => 'day']);
    $once = external($this->memo, ['external_name' => 'Facilitator', 'rate_source' => 'custom', 'rate_amount' => '20000', 'rate_unit' => 'once']);

    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $daily)->estimated_amount)->toBe('12000.00')
        ->and(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $once)->estimated_amount)->toBe('20000.00')
        ->and($this->memo->fresh()->external_count)->toBe(2);
});

it('fills a predefined DSA scale rate and pays it per night', function () {
    $mp = external($this->memo, ['external_name' => 'Hon. Member', 'external_category' => ExternalCategory::Mp, 'rate_source' => 'dsa', 'rate_grade' => '6']);

    $line = costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $mp);
    expect($mp->fresh()->job_grade)->toBe('6')->and($mp->fresh()->rate_unit)->toBe(RateUnit::Night)
        ->and($line->unit_rate)->toBe('11200.00')->and($line->estimated_amount)->toBe(Money::fromCents(1120000 * 5))
        ->and($line->dsa_rate_id)->not->toBeNull();
});

it('recalculates the payment when their dates or rate change, and drops it with the rate', function () {
    $consultant = external($this->memo, ['rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => 'night']);

    // Only 11 and 12 November: 2 days, 3 nights.
    $this->editor->updateExternal($consultant, ['external_name' => 'External Consultant', 'start_date' => '2026-11-11', 'end_date' => '2026-11-12', 'rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => 'night'], ParticipantRole::Member, $this->hod);
    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $consultant)->estimated_amount)->toBe('15000.00');

    // A same-day visit pays no night.
    $this->editor->updateExternal($consultant->fresh(), ['external_name' => 'External Consultant', 'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => 'night'], ParticipantRole::Member, $this->hod);
    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $consultant)->estimated_amount)->toBe('0.00');

    $this->editor->updateExternal($consultant->fresh(), ['external_name' => 'External Consultant'], ParticipantRole::Member, $this->hod);
    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $consultant))->toBeNull()
        ->and($consultant->fresh()->start_date)->toBeNull();
});

it('refuses invalid external participant dates and rates', function (array $data, string $message) {
    expect(fn () => external($this->memo, $data))->toThrow(ActivityWorkflowException::class, $message);
})->with([
    'completion before start' => [['start_date' => '2026-11-12', 'end_date' => '2026-11-11'], 'cannot be before'],
    'outside the activity' => [['start_date' => '2026-11-09', 'end_date' => '2026-11-11'], 'within the activity'],
    'custom rate without an amount' => [['rate_source' => 'custom', 'rate_amount' => '', 'rate_unit' => 'night'], 'rate amount'],
    'custom rate without a unit' => [['rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => ''], 'per night, per day or once'],
    'DSA rate without a scale' => [['rate_source' => 'dsa', 'rate_grade' => ''], 'DSA scale'],
]);

it('never counts an external payment twice: their allowance cannot be typed in as a cost line', function () {
    $consultant = external($this->memo, ['rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => 'night']);
    $allowance = CostCategory::query()->where('code', DsaCalculator::EXTERNAL_CATEGORY_CODE)->value('id');

    expect(fn () => $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $allowance, 'activity_participant_id' => $consultant->id, 'estimated_amount' => '25000'], $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'worked out from the rate');

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->assertDontSee('Allowances (external participants)</option>', false);
});

it('adds an external participant with dates and a rate from the team page, and edits it there', function () {
    $page = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openExternal')
        ->assertSet('externalStart', '2026-11-10')->assertSet('externalEnd', '2026-11-13')
        ->set('externalName', 'Jane Expert')->set('externalDesignation', 'External consultant')
        ->set('externalRateSource', 'custom')->call('addExternal')->assertHasErrors(['externalRateAmount'])
        ->set('externalRateAmount', '5,000')->set('externalRateUnit', 'night')
        ->call('addExternal')->assertHasNoErrors();

    $jane = $this->memo->participants()->where('external_name', 'Jane Expert')->sole();
    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $jane)->estimated_amount)->toBe('25000.00');

    $page->call('openExternal', $jane->id)->assertSet('externalRateAmount', '5000.00')->assertSet('externalDesignation', 'External consultant')
        ->set('externalEnd', '2026-11-11')->call('addExternal')->assertHasNoErrors();
    expect(costOf($this->memo, DsaCalculator::EXTERNAL_CATEGORY_CODE, $jane)->estimated_amount)->toBe('15000.00');
});

// ── Roles ────────────────────────────────────────────────────────────

it('offers the new roles, asks for Other in words, and shows it', function () {
    expect(array_map(fn ($r) => $r->label(), ParticipantRole::cases()))
        ->toBe(['Director', 'CEO', 'HOD', 'Officer', 'Support staff', 'Driver', 'Other (specify)', 'Member', 'Team lead']);

    $officer = Staff::factory()->create();
    expect(fn () => $this->editor->addStaff($this->memo, [$officer->id], ParticipantRole::Other, $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'Say what their role is');

    $this->editor->addStaff($this->memo, [$officer->id], ParticipantRole::Other, $this->hod, roleOther: 'Resource person');
    $participant = $this->memo->participants()->where('staff_id', $officer->id)->sole();
    expect($participant->roleLabel())->toBe('Resource person');

    $this->editor->addStaff($this->memo, [Staff::factory()->create()->id], ParticipantRole::Director, $this->hod);
    expect($this->memo->participants()->where('role', 'director')->sole()->roleLabel())->toBe('Director')
        // Existing records keep their roles.
        ->and($this->memo->participants()->where('role', 'member')->exists())->toBeTrue();
});

// ── The generated memo ───────────────────────────────────────────────

it('generates the memo from the officer\'s designation, with the duration, cost methods and external payments, and no DSA explanation', function () {
    $manager = Staff::factory()->create(['department_id' => $this->ict->id, 'designation_id' => Designation::query()->where('name', 'Manager')->value('id')]);
    $this->hod->update(['staff_id' => $manager->id]);
    $county = County::query()->where('name', 'Kakamega')->firstOrFail();
    $this->editor->addLocation($this->memo, ['county_id' => $county->id], $this->hod);
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'description' => 'Lunch', 'calculation_method' => 'per_person', 'unit_rate' => '4000', 'quantity' => 10], $this->hod);
    $this->editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals, 'description' => 'Activity lunch', 'calculation_method' => 'fixed', 'estimated_amount' => '50000'], $this->hod);
    external($this->memo, ['external_name' => 'Jane Expert', 'external_designation' => 'External consultant', 'rate_source' => 'custom', 'rate_amount' => '5000', 'rate_unit' => 'night']);

    $document = app(MemoDocument::class);
    $data = $document->data($this->memo->fresh());
    expect($data['from'])->toBe('Manager, ICT');

    app(ActivityLifecycle::class)->submitForReview($this->memo->fresh(), $this->hod, acting: true);
    $data = $document->data($this->memo->fresh());
    expect($data['from'])->toBe('Ag. Manager, ICT')
        ->and($data['request'])->toContain('over 4 days and 5 nights')
        ->and($data['total'])->toBe(Money::toCents($this->memo->fresh()->estimated_total));

    $rows = collect($data['detailed']);
    $lunch = $rows->first(fn ($r) => str_contains($r['item'], 'Lunch') && ! str_contains($r['item'], 'Activity'));
    $fixed = $rows->first(fn ($r) => str_contains($r['item'], 'Activity lunch'));
    expect($lunch)->toMatchArray(['pax' => 10, 'rate' => 400000, 'amount' => 4000000, 'days' => null])
        ->and($fixed)->toMatchArray(['pax' => null, 'rate' => null, 'amount' => 5000000])
        ->and($data['externalPayments'])->toHaveCount(1)
        ->and($data['externalPayments'][0])->toMatchArray(['name' => 'Jane Expert', 'designation' => 'External consultant', 'days' => 4, 'nights' => 5, 'units' => '5 nights', 'amount' => 2500000]);

    $html = view('memos.document', $data + ['user' => $this->hod])->render();
    expect($html)->toContain('<td class="k">FROM:</td><td class="v">Ag. Manager, ICT</td>')->not->toContain('DSA is paid per night')
        ->toContain('TOTAL DAYS')->toContain('TOTAL NIGHTS')->toContain('External participants: payments')->toContain('25,000');
});

<?php

use App\Enums\CostMethod;
use App\Enums\TravelMode;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show as ActivityShow;
use App\Livewire\Memos\Team;
use App\Models\ActivityCost;
use App\Models\CostCategory;
use App\Models\Department;
use App\Services\ActivityEditor;
use App\Services\MemoDocument;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->hod = headOfDepartment(Department::query()->where('name', 'ICT')->firstOrFail());
    $this->memo = departmentMemo($this->hod, ['days' => 3]);
    $this->travel = CostCategory::query()->where('code', 'travel')->firstOrFail();
    $this->meals = CostCategory::query()->where('code', 'meals')->firstOrFail();
    $this->venue = CostCategory::query()->where('code', 'venue')->firstOrFail();
});

it('offers travel by its mode in the one cost-type list, setting the travel mode and how it is usually calculated', function () {
    $team = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])->call('openCost');
    $options = collect($team->instance()->costTypeOptions())->pluck('label');

    expect($options)->toContain('Travel: '.TravelMode::Air->label(), 'Travel: '.TravelMode::RoadPublic->label(), 'Meals')
        ->not->toContain('Travel')->not->toContain('DSA');

    $team->set('costType', $this->travel->id.'|'.TravelMode::Air->value)
        ->assertSet('costCategoryId', (string) $this->travel->id)
        ->assertSet('costTravelMode', TravelMode::Air->value)
        ->assertSet('costMethod', CostMethod::PerPerson->value)
        ->set('costType', (string) $this->venue->id)
        ->assertSet('costTravelMode', '')
        ->assertSet('costMethod', CostMethod::Fixed->value);
});

it('works out a cost per person per day, and needs the days', function () {
    $editor = app(ActivityEditor::class);

    $editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals->id, 'calculation_method' => 'per_person_per_day', 'unit_rate' => '1,500', 'quantity' => 10, 'days' => 3], $this->hod);
    $line = ActivityCost::query()->where('cost_category_id', $this->meals->id)->sole();

    expect($line)->estimated_amount->toBe('45000.00')->days->toBe(3)
        ->and($line->calculationLabel())->toBe('KES 1,500.00 × 10 people × 3 days')
        ->and(collect(app(MemoDocument::class)->data($this->memo->fresh())['detailed'])->firstWhere('item', 'Meals'))->days->toBe('3')->pax->toBe(10)
        ->and(fn () => $editor->savePlannedCost($this->memo, ['cost_category_id' => $this->meals->id, 'calculation_method' => 'per_person_per_day', 'unit_rate' => '1500', 'quantity' => 10], $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'number of days');
});

it('adds several lines in one popup and saves them together', function () {
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openCost')
        ->set('costType', (string) $this->meals->id)->set('costMethod', 'per_person_per_day')->set('costUnitRate', '1500')->set('costPeople', '10')->set('costDays', '3')
        ->call('queueCostLine')->assertHasNoErrors()
        ->assertSet('costType', '')->assertSee('Lines to save (1)')->assertSee('45,000.00')
        ->set('costType', $this->travel->id.'|'.TravelMode::Air->value)->set('costUnitRate', '25000')->set('costPeople', '2')
        ->call('queueCostLine')->assertHasNoErrors()
        ->set('costType', (string) $this->venue->id)->set('costAmount', '60,000')
        ->call('saveCost')->assertHasNoErrors();

    $lines = ActivityCost::query()->where('activity_id', $this->memo->id)->where('is_computed', false)->get();
    expect($lines)->toHaveCount(3)
        ->and($lines->firstWhere('cost_category_id', $this->travel->id))->travel_mode->toBe(TravelMode::Air)->estimated_amount->toBe('50000.00')
        ->and($lines->firstWhere('cost_category_id', $this->venue->id)->estimated_amount)->toBe('60000.00');
});

it('saves nothing when the line in the form is wrong, keeping the lines listed', function () {
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openCost')
        ->set('costType', (string) $this->venue->id)->set('costAmount', '60,000')
        ->call('queueCostLine')
        ->set('costType', (string) $this->meals->id)->set('costMethod', 'per_person')->set('costUnitRate', '')
        ->call('saveCost')->assertHasErrors(['costUnitRate'])
        ->assertSee('Lines to save (1)')
        ->call('unqueueCostLine', 0)->assertDontSee('Lines to save');

    expect(ActivityCost::query()->where('activity_id', $this->memo->id)->where('is_computed', false)->count())->toBe(0);
});

it('lets the Office of the CEO save several lines together too', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);

    Livewire::actingAs($cos)->test(ActivityShow::class, ['activity' => $activity])
        ->call('openCost')
        ->set('costType', (string) $this->venue->id)->set('costAmount', '40,000')
        ->call('queueCostLine')
        ->set('costType', $this->travel->id.'|'.TravelMode::RoadBoardVehicle->value)->set('costUnitRate', '3000')->set('costPeople', '4')
        ->call('saveCost')->assertHasNoErrors();

    expect($activity->costs()->where('is_computed', false)->count())->toBe(2)
        ->and($activity->costs()->where('travel_mode', TravelMode::RoadBoardVehicle->value)->value('estimated_amount'))->toBe('12000.00');
});

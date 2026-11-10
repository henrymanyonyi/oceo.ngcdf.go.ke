<?php

use App\Enums\ActivityStatus;
use App\Enums\BudgetStatus;
use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Form;
use App\Livewire\Memos\Team;
use App\Models\Activity;
use App\Models\BudgetLine;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Office;
use App\Models\Region;
use App\Models\Staff;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Services\MemoDocument;
use App\Services\ReportCompliance;
use App\Services\StaffChanges;
use App\Support\FinancialYear;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->editor = app(ActivityEditor::class);
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->programmes = Department::query()->where('name', 'Programmes')->firstOrFail();
    $this->coast = Region::query()->where('code', 'CST')->firstOrFail();
    $this->western = Region::query()->where('name', 'like', 'Western%')->firstOrFail();
    $this->rc = User::factory()->coordinatorOf($this->coast, $this->programmes)->create();
    $this->fy = FinancialYear::for(today()->addDays(30));
    $this->line = regionalWorkplanLine($this->coast, $this->programmes, $this->fy->label(), 'Coast constituency monitoring visits');
});

/** An approved regional workplan with one activity line. Returns the line. */
function regionalWorkplanLine(Region $region, Department $department, string $financialYear, string $name, string $amount = '900000'): BudgetLine
{
    $budget = DepartmentBudget::query()->firstOrNew(['region_id' => $region->id, 'financial_year' => $financialYear], ['department_id' => $department->id]);
    $budget->forceFill(['department_id' => $department->id, 'status' => BudgetStatus::Approved, 'approved_at' => now()])->save();

    return $budget->lines()->create([
        'department_id' => $department->id, 'financial_year' => $financialYear, 'name' => $name, 'amount' => $amount,
        'objective' => 'Monitor project implementation in the region', 'deliverables' => 'Monitoring report per constituency',
    ]);
}

/** A Regional Coordinator's memo from the workplan line, with a venue and one officer, ready to submit. */
function regionalMemo(User $rc, BudgetLine $line, array $overrides = []): Activity
{
    $memo = departmentMemo($rc, array_merge(['budget_line_id' => $line->id, 'title' => 'Coast monitoring visit'], $overrides));

    return $memo->fresh();
}

describe('originating memos', function () {
    it('lets a Regional Coordinator prepare a memo from their region\'s approved workplan, recording the region and themselves', function () {
        $memo = regionalMemo($this->rc, $this->line);

        expect($memo)
            ->originating_region_id->toBe($this->coast->id)
            ->organising_department_id->toBe($this->programmes->id)
            ->responsible_hod_id->toBe($this->rc->id)
            ->budget_line_id->toBe($this->line->id)
            ->and(app(MemoDocument::class)->data($memo)['from'])->toBe('Regional Coordinator, '.$this->coast->name);
    });

    it('refuses a line of another region\'s workplan or of a department\'s own budget', function () {
        $western = regionalWorkplanLine($this->western, $this->programmes, $this->fy->label(), 'Western visits');
        $departmentLine = approvedBudgetLine($this->programmes, $this->fy->label(), '500000');

        expect(fn () => regionalMemo($this->rc, $western))->toThrow(AuthorizationException::class)
            ->and(fn () => regionalMemo($this->rc, $departmentLine))->toThrow(AuthorizationException::class);

        $memo = regionalMemo($this->rc, $this->line);
        expect(fn () => $this->editor->update($memo, ['budget_line_id' => $western->id], $this->rc))->toThrow(AuthorizationException::class);
    });

    it('needs the workplan activity before a regional memo is submitted, and warns of another memo on it', function () {
        $memo = regionalMemo($this->rc, $this->line, ['budget_line_id' => null]);

        expect(fn () => $this->lifecycle->submitForReview($memo, $this->rc))->toThrow(ActivityWorkflowException::class, 'approved workplan');

        $this->editor->update($memo, ['budget_line_id' => $this->line->id], $this->rc);
        $this->lifecycle->submitForReview($memo->fresh(), $this->rc, ReviewOffice::AssistantChiefOfStaff);

        $second = regionalMemo($this->rc, $this->line);
        $warning = collect(app(MemoDocument::class)->issues($second))->firstWhere('blocking', false);
        expect(collect(app(MemoDocument::class)->issues($second))->where('blocking', false)->pluck('message')->implode(' '))->toContain('already has 1 other memo');
    });

    it('fills the title, purpose and outputs from the workplan activity chosen, leaving what is typed', function () {
        Livewire::actingAs($this->rc)->test(Form::class)
            ->set('title', 'My own title')
            ->set('start_date', today()->addDays(30)->toDateString())
            ->set('budget_line_id', (string) $this->line->id)
            ->assertSet('title', 'My own title')
            ->assertSet('purpose', 'Monitor project implementation in the region')
            ->assertSet('expected_outputs', 'Monitoring report per constituency')
            ->assertSee('Coast constituency monitoring visits')
            ->assertSee('Workplan activity');
    });

    it('sends a regional memo along the same routes to the CEO and tells the coordinator, not the department\'s Head', function () {
        $hod = headOfDepartment($this->programmes);
        $memo = regionalMemo($this->rc, $this->line);

        $this->lifecycle->submitDirectToCeo($memo, $this->rc);
        $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, ceo());

        expect($memo->fresh()->status)->toBe(ActivityStatus::Approved)
            ->and($this->rc->notifications()->count())->toBeGreaterThan(0)
            ->and($hod->notifications()->count())->toBe(0);
    });
});

describe('access between regions and departments', function () {
    it('9: keeps each region\'s memos to its own coordinator, and out of the department\'s Head\'s view', function () {
        $memo = regionalMemo($this->rc, $this->line);
        $westernRc = User::factory()->coordinatorOf($this->western, $this->programmes)->create();
        $hod = headOfDepartment($this->programmes);
        $departmentMemo = departmentMemo($hod);

        expect(Activity::query()->visibleTo($this->rc)->pluck('id')->all())->toBe([$memo->id])
            ->and(Activity::query()->visibleTo($westernRc)->count())->toBe(0)
            ->and(Activity::query()->visibleTo($hod)->pluck('id')->all())->toBe([$departmentMemo->id]);

        $this->actingAs($westernRc)->get(route('memos.show', $memo))->assertForbidden();
        $this->actingAs($westernRc)->get(route('memos.document', $memo))->assertForbidden();
        $this->actingAs($hod)->get(route('memos.show', $memo))->assertForbidden();
        $this->actingAs($this->rc)->get(route('memos.show', $departmentMemo))->assertForbidden();
        $this->actingAs($this->rc)->get(route('memos.show', $memo))->assertOk();

        expect(fn () => $this->editor->update($memo, ['title' => 'Changed'], $westernRc))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->lifecycle->submitForReview($memo, $westernRc))->toThrow(AuthorizationException::class);
    });

    it('9: keeps each region\'s workplan to its own coordinator and the Office of the CEO', function () {
        $westernRc = User::factory()->coordinatorOf($this->western, $this->programmes)->create();
        $hod = headOfDepartment($this->programmes);
        $budget = $this->line->budget;

        expect(Budgeting::canView($this->rc, $budget))->toBeTrue()
            ->and(Budgeting::canView($westernRc, $budget))->toBeFalse()
            ->and(Budgeting::canView($hod, $budget))->toBeFalse()
            ->and(Budgeting::canView(chiefOfStaff(), $budget))->toBeTrue();

        $this->actingAs($westernRc)->get(route('budgets.show', $budget))->assertForbidden();
        $this->actingAs($hod)->get(route('budgets.show', $budget))->assertForbidden();
        $this->actingAs($this->rc)->get(route('budgets.show', $budget))->assertOk();

        expect(fn () => app(Budgeting::class)->saveLine($budget, ['name' => 'Slipped in'], $westernRc))->toThrow(AuthorizationException::class)
            ->and(fn () => app(Budgeting::class)->create($this->programmes->id, FinancialYear::for(today()->addYear()->addDays(30))->label(), null, $westernRc, regionId: $this->coast->id))->toThrow(AuthorizationException::class)
            ->and(fn () => app(Budgeting::class)->create($this->programmes->id, FinancialYear::for(today()->addYear()->addDays(30))->label(), null, $this->rc))->toThrow(AuthorizationException::class);
    });

    it('lets a coordinator prepare their region\'s workplan once a year, apart from the department\'s own budget', function () {
        approvedBudgetLine($this->programmes, FinancialYear::for(today()->addYear()->addDays(30))->label(), '100');
        $next = FinancialYear::for(today()->addYear()->addDays(30))->label();

        $workplan = app(Budgeting::class)->create($this->programmes->id, $next, 'Coast plan', $this->rc, regionId: $this->coast->id);

        expect($workplan)->region_id->toBe($this->coast->id)->department_id->toBe($this->programmes->id)
            ->and(fn () => app(Budgeting::class)->create($this->programmes->id, $next, null, $this->rc, regionId: $this->coast->id))->toThrow(ActivityWorkflowException::class, 'region already has a workplan');

        $this->actingAs($this->rc)->get(route('budgets.index', ['fy' => $next]))->assertOk()->assertSee($this->coast->name)->assertDontSee($this->western->name);
        $this->actingAs(ceo())->get(route('budgets.index', ['fy' => $next]))->assertOk()->assertSee($this->coast->name)->assertSee('Programmes');
    });

    it('lists nobody in a coordinator\'s team picker until they search, then any officer by name', function () {
        $coastOffice = Office::query()->where('region_id', $this->coast->id)->firstOrFail();
        Staff::factory()->create(['name' => 'Coast Officer', 'office_id' => $coastOffice->id]);
        Staff::factory()->create(['name' => 'Nairobi Officer', 'office_id' => null]);
        $memo = regionalMemo($this->rc, $this->line);

        Livewire::actingAs($this->rc)->test(Team::class, ['activity' => $memo])->set('showAdd', true)
            ->assertDontSee('Coast Officer')->assertDontSee('Nairobi Officer')
            ->set('staffSearch', 'Coast Off')->assertSee('Coast Officer')
            ->set('staffSearch', 'Nairobi Off')->assertSee('Nairobi Officer');
    });
});

describe('references, compliance and budget position by region', function () {
    it('numbers regional memos in the region\'s own sequence, apart from the department\'s', function () {
        $hod = headOfDepartment($this->programmes);
        $regional = regionalMemo($this->rc, $this->line);
        $own = departmentMemo($hod);

        $this->lifecycle->submitForReview($regional, $this->rc);
        $this->lifecycle->submitForReview($own, $hod);

        $fy = FinancialYear::for(today())->shortLabel();
        expect($regional->fresh()->memo_number)->toBe("NG-CDF BOARD/RC/CST/001/{$fy}")
            ->and($own->fresh()->memo_number)->toBe("NG-CDF BOARD/P/001/{$fy}");
    });

    it('holds back a region for its own overdue reports only, and the department for its own', function () {
        $hod = headOfDepartment($this->programmes);
        $overdue = regionalMemo($this->rc, $this->line);
        $overdue->forceFill(['status' => ActivityStatus::Completed, 'report_due_on' => today()->subDays(2)])->saveQuietly();

        expect(fn () => $this->lifecycle->submitForReview(regionalMemo($this->rc, $this->line), $this->rc))->toThrow(ActivityWorkflowException::class, 'the region has 1 overdue')
            ->and(app(ReportCompliance::class)->outstandingFor(departmentMemo($hod)))->toHaveCount(0);

        $this->lifecycle->submitForReview(departmentMemo($hod), $hod);
        expect(Activity::query()->whereNull('originating_region_id')->status(ActivityStatus::UnderReview)->count())->toBe(1);
    });

    it('counts a region\'s workplan and memos in its own position, not the department\'s', function () {
        approvedBudgetLine($this->programmes, $this->fy->label(), '200000');

        $position = app(BudgetPosition::class);
        expect($position->forRegion($this->fy, $this->coast->id)['allocation'])->toBe(900000_00)
            ->and($position->forDepartment($this->fy, $this->programmes->id)['allocation'])->toBe(200000_00)
            // Board-wide, the region's money still counts under its department.
            ->and($position->forYear($this->fy, $this->programmes->id)->first()['allocation'])->toBe(1100000_00);
    });
});

describe('accounts', function () {
    it('appoints a Regional Coordinator from the staff list, for the region of their duty station, one per region', function () {
        $office = Office::query()->where('region_id', $this->western->id)->firstOrFail();
        $officer = Staff::factory()->create(['name' => 'Wanjala Coordinator', 'email' => 'wanjala@ngcdf.go.ke', 'office_id' => $office->id, 'department_id' => $this->programmes->id]);
        $second = Staff::factory()->create(['email' => 'second@ngcdf.go.ke', 'office_id' => $office->id, 'department_id' => $this->programmes->id]);
        $change = ['department_id' => $this->programmes->id, 'access' => User::ROLE_RC, 'effective_on' => today()->toDateString(), 'reason' => 'Appointed'];

        $account = app(StaffChanges::class)->apply($officer, $change, User::factory()->admin()->create())['account'];

        expect($account->fresh())->region_id->toBe($this->western->id)->department_id->toBe($this->programmes->id)
            ->and($account->fresh()->isRegionalCoordinator())->toBeTrue()
            ->and(fn () => app(StaffChanges::class)->apply($second, $change, User::factory()->admin()->create()))->toThrow(ActivityWorkflowException::class, 'active Regional Coordinator');
    });

    it('creates a Regional Coordinator account from the command line, one per region', function () {
        $this->artisan('activities:create-user', ['role' => User::ROLE_RC, 'email' => 'rc.western@ngcdf.go.ke', 'name' => 'Western RC', '--region' => $this->western->code, '--department' => 'Programmes'])->assertSuccessful();
        $this->artisan('activities:create-user', ['role' => User::ROLE_RC, 'email' => 'rc2.western@ngcdf.go.ke', 'name' => 'Another', '--region' => $this->western->code, '--department' => 'Programmes'])->assertFailed();

        expect(User::query()->where('email', 'rc.western@ngcdf.go.ke')->sole()->region_id)->toBe($this->western->id);
    });
});

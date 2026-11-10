<?php

namespace App\Livewire\Budgets;

use App\Enums\BudgetStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Region;
use App\Models\User;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Budgeting › Department budgets: every department's budget for the chosen
 * financial year, its approval status and its position (allocation, spent,
 * committed, balance). The CEO, the Chief of Staff and the Assistant prepare
 * drafts here; only the CEO approves. A Head of Department sees only their
 * own department here (Workplan and budget) and prepares its workplan.
 */
#[Layout('layouts.admin')]
#[Title('Department budgets')]
class Index extends Component
{
    use RunsActions;
    use WithFileUploads;

    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showPrepare = false;

    public string $prepareDepartment = '';

    /** A region's workplan (Regional Coordinator, or the Office of the CEO for a region); '' for a department's own budget. */
    public string $prepareRegion = '';

    public string $prepareYear = '';

    public string $prepareNotes = '';

    /** A workplan already in the template, imported as the draft's lines when it is created. */
    public $prepareFile = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['budgets.prepare', 'budgets.approve', 'workplans.prepare']), 403);
        $this->fy = $this->fy ?: FinancialYear::current()->label();
    }

    /** A Head of Department's own department; null for the Office of the CEO, who see every department. */
    private function ownDepartment(): ?int
    {
        $user = Auth::user();

        return $user->canAny(['budgets.prepare', 'budgets.approve']) || $user->isRegionalCoordinator() ? null : (int) $user->department_id;
    }

    /** A Regional Coordinator's own region: they see and prepare only its workplan. */
    private function ownRegion(): ?int
    {
        $user = Auth::user();

        return ! $user->canAny(['budgets.prepare', 'budgets.approve']) && $user->isRegionalCoordinator() ? (int) $user->region_id : null;
    }

    public function openPrepare(?int $departmentId = null, ?int $regionId = null): void
    {
        $this->resetValidation();
        $this->prepareRegion = (string) ($this->ownRegion() ?? $regionId ?? '');
        $this->prepareDepartment = (string) ($this->ownDepartment() ?? ($this->prepareRegion !== '' ? $this->regionDepartment((int) $this->prepareRegion) : $departmentId) ?? '');
        $this->prepareYear = $this->fy;
        $this->prepareNotes = '';
        $this->prepareFile = null;
        $this->showPrepare = true;
    }

    public function prepare(Budgeting $budgeting): void
    {
        if ($region = $this->ownRegion()) {
            $this->prepareRegion = (string) $region;
            $this->prepareDepartment = (string) Auth::user()->department_id;
        }
        $this->validate([
            'prepareRegion' => ['nullable', 'exists:regions,id'],
            'prepareDepartment' => ['required', 'exists:departments,id'],
            'prepareYear' => ['required', 'regex:/^\d{4}\/\d{2}$/'],
            'prepareNotes' => ['nullable', 'string', 'max:2000'],
            'prepareFile' => ['nullable', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')],
        ], ['prepareDepartment.required' => 'Choose the department. A region\'s workplan is under its Regional Coordinator\'s department; give the region a coordinator first.'], ['prepareDepartment' => 'department', 'prepareYear' => 'financial year', 'prepareNotes' => 'notes', 'prepareFile' => 'workplan file']);

        $budget = $this->attempt(fn () => $budgeting->create($this->ownDepartment() ?? (int) $this->prepareDepartment, $this->prepareYear, $this->prepareNotes, Auth::user(), regionId: $this->prepareRegion !== '' ? (int) $this->prepareRegion : null));
        if (! $budget instanceof DepartmentBudget) {
            return;
        }

        if ($this->prepareFile) {
            $result = $this->attempt(fn () => $budgeting->importLines($budget, $this->prepareFile, Auth::user()));
            if (is_array($result)) {
                session()->flash('notify', $result['errors'] === []
                    ? ['type' => 'success', 'message' => "Draft created with {$result['added']} lines from your workplan. Check them, then submit to the CEO."]
                    : ['type' => 'error', 'message' => 'Draft created, but the workplan file was not imported: '.implode(' ', array_slice($result['errors'], 0, 3)).' Fix the file and use Import from template.']);
            }
        }

        $this->redirectRoute('budgets.show', $budget, navigate: true);
    }

    /** The department a region's workplan sits under: its active Regional Coordinator's. */
    private function regionDepartment(int $regionId): ?int
    {
        return User::query()->role(User::ROLE_RC)->where('is_active', true)->where('region_id', $regionId)->value('department_id');
    }

    public function render(BudgetPosition $position, Budgeting $budgeting): View
    {
        $fy = FinancialYear::fromLabel($this->fy);
        $own = $this->ownDepartment();
        $ownRegion = $this->ownRegion();
        $office = ! $own && ! $ownRegion;

        // Departments' own budgets (a region's workplan is listed with the regions, never under its department).
        $budgets = $ownRegion ? collect() : DepartmentBudget::query()->where('financial_year', $fy->label())->whereNull('region_id')->when($own, fn ($q, $id) => $q->where('department_id', $id))
            ->withCount('lines')->with(['preparer'])->get()->keyBy('department_id');
        $yearFigures = $ownRegion ? collect() : $position->forYear($fy, $own, includeRegional: false);
        $figures = $yearFigures->keyBy(fn (array $r) => $r['department']?->id);

        $departmentRows = $ownRegion ? collect() : Department::query()->active()->when($own, fn ($q, $id) => $q->whereKey($id))->ordered()->get()
            ->merge(Department::query()->whereIn('id', $budgets->keys())->get())
            ->unique('id')
            ->map(function (Department $d) use ($budgets, $figures, $budgeting) {
                $budget = $budgets->get($d->id);

                return [
                    'key' => 'd-'.$d->id,
                    'name' => $d->name,
                    'department' => $d,
                    'region' => null,
                    'budget' => $budget,
                    'totals' => $budget ? $budgeting->totals($budget) : null,
                    'position' => $figures->get($d->id),
                ];
            });

        // Regions' workplans: every region for the Office of the CEO, their own for a Regional Coordinator.
        $regionBudgets = $own ? collect() : DepartmentBudget::query()->where('financial_year', $fy->label())->whereNotNull('region_id')->when($ownRegion, fn ($q, $id) => $q->where('region_id', $id))
            ->withCount('lines')->with(['preparer', 'department'])->get()->keyBy('region_id');
        $regionFigures = $own ? collect() : $position->forRegions($fy, $ownRegion);
        $regionRows = $own ? collect() : Region::query()->when($ownRegion, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where(fn ($r) => $r->where('is_active', true)->orWhereIn('id', $regionBudgets->keys())))->orderBy('name')->get()
            ->map(function (Region $region) use ($regionBudgets, $regionFigures, $budgeting) {
                $budget = $regionBudgets->get($region->id);

                return [
                    'key' => 'r-'.$region->id,
                    'name' => $region->name,
                    'department' => $budget?->department,
                    'region' => $region,
                    'budget' => $budget,
                    'totals' => $budget ? $budgeting->totals($budget) : null,
                    'position' => $regionFigures->first(fn (array $r) => $r['region']?->id === $region->id),
                ];
            });

        $rows = $departmentRows->concat($regionRows)
            ->filter(fn (array $r) => match ($this->status) {
                '' => true,
                'none' => $r['budget'] === null,
                default => $r['budget']?->status->value === $this->status,
            })
            ->values();

        $all = $budgets->values()->concat($regionBudgets->values());

        return view('livewire.budgets.index', [
            'rows' => $rows,
            'years' => FinancialYear::options(),
            'totals' => $position->totals($yearFigures->concat($regionFigures)),
            'statusCounts' => collect(BudgetStatus::cases())->mapWithKeys(fn (BudgetStatus $s) => [$s->value => $all->where('status', $s)->count()]),
            'withoutBudget' => $rows->filter(fn (array $r) => $r['budget'] === null)->count(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'canPrepare' => Budgeting::canPrepare(Auth::user()) || ($own && Budgeting::canPrepareFor(Auth::user(), $own)) || ($ownRegion && Budgeting::canPrepareFor(Auth::user(), (int) Auth::user()->department_id, $ownRegion)),
            'ownDepartment' => $own ?: $ownRegion,
            'ownRegion' => $ownRegion,
            'office' => $office,
            'regions' => Region::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canApprove' => Budgeting::canApprove(Auth::user()),
        ])->title($ownRegion ? 'Regional workplan' : ($own ? 'Workplan and budget' : 'Department budgets'));
    }
}

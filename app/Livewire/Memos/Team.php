<?php

namespace App\Livewire\Memos;

use App\Enums\EmploymentType;
use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\RateUnit;
use App\Enums\TravelMode;
use App\Livewire\Concerns\AddsMissingOfficers;
use App\Livewire\Concerns\EntersCostLines;
use App\Livewire\Concerns\EntersExternalParticipants;
use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DsaRate;
use App\Models\Office;
use App\Models\Staff;
use App\Rules\DsaGrade;
use App\Services\AccessLogger;
use App\Services\ActivityEditor;
use App\Services\AppSettings;
use App\Services\DsaCalculator;
use App\Services\ParticipantChecks;
use App\Services\StaffVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A Head of Department builds the team and planned costs of their own memo
 * while it is with them (a draft, or returned for correction), and adds an
 * officer to the staff list if one is missing. DSA is computed from each
 * officer's job grade and the location, as for the Office of the CEO.
 *
 * Deliberately narrower than the Office of the CEO's activity page: an
 * officer held by another approved activity is shown as unavailable, with
 * the days but without naming the activity, and cannot be added (only a
 * release, or the Office of the CEO, can clear it); no field-day totals
 * across activities are shown.
 */
#[Layout('layouts.admin')]
class Team extends Component
{
    use AddsMissingOfficers;
    use EntersCostLines;
    use EntersExternalParticipants;
    use RunsActions;
    use WithFileUploads;

    public Activity $activity;

    /** Steps 4 and 5 of the one-page memo builder (Memos\Compose), without the page shell. */
    public bool $embedded = false;

    public bool $showAdd = false;

    public bool $showExternal = false;

    public bool $showRemove = false;

    public bool $showDays = false;

    public bool $showCost = false;

    // Add officers.
    public string $staffSearch = '';

    public string $staffDepartment = '';

    /** @var list<int|string> */
    public array $selectedStaff = [];

    public string $role = 'member';

    // A group added by number, without names (e.g. 10 FAMs).
    public bool $showGroup = false;

    public string $groupLabel = '';

    public string $groupCount = '';

    public string $groupGrade = '';

    // One participant's days.
    public ?int $participantId = null;

    public string $daysStart = '';

    public string $daysEnd = '';

    // A planned cost line.
    public ?int $costId = null;

    public string $costCategoryId = '';

    public string $costParticipantId = '';

    public string $costDescription = '';

    public string $costTravelMode = '';

    // An officer missing from the staff list.

    public function mount(Activity $activity, AccessLogger $log, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        abort_unless($activity->isVisibleTo(Auth::user()), 403);
        $this->activity = $activity;
        if (! $this->embedded) {
            $log->log(AccessLogger::VIEW, $activity, ['page' => 'team']);
        }
    }

    /** The memo's dates, venue or details changed elsewhere on the builder page: DSA and the notices may have changed. */
    #[On('memo-changed')]
    #[On('memo-saved')]
    public function memoChanged(): void
    {
        $this->activity->refresh();
    }

    private function done(string $message, array $warnings = []): void
    {
        $this->reset([
            'showAdd', 'showExternal', 'showGroup', 'groupLabel', 'groupCount', 'groupGrade', 'showRemove', 'showDays', 'showCost', 'showStaffForm',
            'staffSearch', 'staffDepartment', 'selectedStaff', 'role', 'roleOther', 'participantId', 'daysStart', 'daysEnd',
            'costId', 'costCategoryId', 'costParticipantId', 'costDescription', 'costTravelMode', 'costQueue',
            ...self::EXTERNAL_FIELDS,
        ]);
        $this->resetCostMethod();
        $this->activity->refresh();
        $this->toast($message);
        $this->warn($warnings);
        $this->dispatch('team-changed');
    }

    // ── Team ───────────────────────────────────────────────────────────

    public function addStaff(ActivityEditor $editor): void
    {
        $this->validate([
            'selectedStaff' => ['required', 'array', 'min:1'],
            'selectedStaff.*' => ['integer', 'exists:staff,id'],
            ...$this->roleRules(),
        ], ['selectedStaff.required' => 'Tick at least one officer.'], ['selectedStaff' => 'officers', 'roleOther' => 'role']);

        $result = $this->attempt(fn () => $editor->addStaff($this->activity, array_map('intval', $this->selectedStaff), ParticipantRole::from($this->role), Auth::user(), roleOther: $this->roleOther));

        if (is_array($result)) {
            $this->done($result['added'].' added to the team.', $result['warnings']);
        }
    }

    /**
     * Officers shown in Add officers: active, not on the team, matching the search and department.
     *
     * @return Collection<int, Staff>
     */
    private function candidates(): Collection
    {
        $onTeam = $this->activity->participants()->whereNotNull('staff_id')->pluck('staff_id')->all();

        // Own department to browse; other departments by name or PF number (App\Services\StaffVisibility).
        return app(StaffVisibility::class)->forPicker(Staff::query()->active(), Auth::user(), $this->staffSearch)->whereNotIn('id', $onTeam)
            ->when($this->staffDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->with(['department', 'designation'])->orderBy('name')->limit(100)->get();
    }

    /**
     * Ticks every officer shown who can be added, or clears them if they are all ticked already.
     * Officers on another approved activity on these days are left out: a Head of Department cannot add them.
     */
    public function toggleShown(ParticipantChecks $checks): void
    {
        $unavailable = $checks->unavailableStaff($this->activity->start_date, $this->activity->end_date, $this->activity->id);
        $shown = $this->candidates()->pluck('id')->reject(fn (int $id) => isset($unavailable[$id]));
        $selected = collect($this->selectedStaff)->map(fn ($id) => (int) $id);

        $this->selectedStaff = ($shown->diff($selected)->isEmpty() ? $selected->diff($shown) : $selected->merge($shown))->unique()->values()->all();
    }

    /** Add an external participant, or change one ($id). */
    public function openExternal(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset([...self::EXTERNAL_FIELDS, 'role', 'roleOther']);
        $this->externalStart = $this->activity->start_date->toDateString();
        $this->externalEnd = $this->activity->end_date->toDateString();

        if ($id) {
            $this->fillExternal($this->activity->participants()->where('is_external', true)->findOrFail($id));
        }

        $this->showExternal = true;
    }

    public function addExternal(ActivityEditor $editor): void
    {
        $this->validate($this->externalRules(), $this->externalMessages(), self::EXTERNAL_ATTRIBUTES);

        $participant = $this->externalId ? $this->activity->participants()->where('is_external', true)->findOrFail($this->externalId) : null;
        $result = $this->attempt(fn () => $participant
            ? $editor->updateExternal($participant, $this->externalData(), ParticipantRole::from($this->role), Auth::user())
            : $editor->addExternal($this->activity, $this->externalData(), ParticipantRole::from($this->role), Auth::user()));

        if ($result !== null) {
            $this->done($participant ? 'External participant updated.' : 'External participant added.');
        }
    }

    /** Officers added by number without their names, e.g. 10 Fund Account Managers. */
    public function addGroup(ActivityEditor $editor): void
    {
        $this->validate([
            'groupLabel' => ['required', 'string', 'max:255'],
            'groupCount' => ['required', 'integer', 'min:1', 'max:500'],
            'groupGrade' => ['required', new DsaGrade(EmploymentType::Attachee)],
            ...$this->roleRules(),
        ], [], ['groupLabel' => 'who they are', 'groupCount' => 'number', 'groupGrade' => 'DSA scale', 'roleOther' => 'role']);

        if ($this->attempt(fn () => $editor->addGroup($this->activity, [
            'group_label' => $this->groupLabel,
            'headcount' => $this->groupCount,
            'job_grade' => $this->groupGrade,
            'role_other' => $this->roleOther,
        ], ParticipantRole::from($this->role), Auth::user()))) {
            $this->done("{$this->groupCount} × {$this->groupLabel} added. DSA is worked out for each of them.");
        }
    }

    public function confirmRemove(int $id): void
    {
        $this->activity->participants()->findOrFail($id);
        $this->participantId = $id;
        $this->showRemove = true;
    }

    public function removeParticipant(ActivityEditor $editor): void
    {
        $participant = $this->activity->participants()->findOrFail($this->participantId);

        if ($this->attempt(fn () => $editor->removeParticipant($participant, Auth::user())) !== null) {
            $this->done($participant->displayName().' removed from the team.');
        }
    }

    public function openDays(int $id): void
    {
        $participant = $this->activity->participants()->findOrFail($id);
        $this->resetErrorBag();
        $this->participantId = $id;
        $this->daysStart = $participant->startOn($this->activity)->toDateString();
        $this->daysEnd = $participant->endOn($this->activity)->toDateString();
        $this->showDays = true;
    }

    public function saveDays(ActivityEditor $editor): void
    {
        $this->validate([
            'daysStart' => ['required', 'date', 'after_or_equal:'.$this->activity->start_date->toDateString(), 'before_or_equal:'.$this->activity->end_date->toDateString()],
            'daysEnd' => ['required', 'date', 'after_or_equal:daysStart', 'before_or_equal:'.$this->activity->end_date->toDateString()],
        ], [
            'daysStart.after_or_equal' => 'The first day must be within the activity.',
            'daysStart.before_or_equal' => 'The first day must be within the activity.',
            'daysEnd.after_or_equal' => 'The last day cannot be before the first day.',
            'daysEnd.before_or_equal' => 'The last day must be within the activity, which ends '.$this->activity->end_date->format('d M Y').'.',
        ], ['daysStart' => 'first day', 'daysEnd' => 'last day']);

        $start = Carbon::parse($this->daysStart);
        $end = Carbon::parse($this->daysEnd);

        $participant = $this->activity->participants()->findOrFail($this->participantId);

        if ($this->attempt(fn () => $editor->setParticipantDates($participant, $start, $end, Auth::user())) !== null) {
            $this->done($participant->displayName()."'s days updated.");
        }
    }

    // ── Costs ──────────────────────────────────────────────────────────

    public function openCost(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset(['costCategoryId', 'costParticipantId', 'costDescription', 'costTravelMode', 'costQueue']);
        $this->resetCostMethod();
        $this->costId = $id;

        if ($id) {
            $line = $this->activity->costs()->where('is_computed', false)->findOrFail($id);
            $this->costCategoryId = (string) $line->cost_category_id;
            $this->costParticipantId = (string) ($line->activity_participant_id ?? '');
            $this->costDescription = (string) $line->description;
            $this->costTravelMode = (string) ($line->travel_mode?->value ?? '');
            $this->fillCostMethod($line);
        }

        $this->showCost = true;
    }

    /** The line in the cost popup, checked, as ActivityEditor::savePlannedCost() takes it (EntersCostLines). */
    protected function validatedCostLine(): ?array
    {
        $this->validate([
            // DSA and external participants' allowances are calculated, never typed in.
            'costCategoryId' => ['required', Rule::exists('cost_categories', 'id')->where(fn ($q) => $q->whereNotIn('code', DsaCalculator::COMPUTED_CODES))],
            'costParticipantId' => ['nullable', 'integer'],
            'costDescription' => ['nullable', 'string', 'max:255'],
            'costTravelMode' => ['nullable', 'in:'.implode(',', array_column(TravelMode::cases(), 'value'))],
            ...$this->costMethodRules(),
        ], ['costCategoryId.required' => 'Choose the cost type.'] + $this->costMethodMessages(), ['costCategoryId' => 'cost type'] + $this->costMethodAttributes());

        if ($this->costParticipantId && ! $this->activity->participants()->whereKey($this->costParticipantId)->exists()) {
            $this->addError('costParticipantId', 'Choose someone on this team.');

            return null;
        }

        return [
            'cost_category_id' => (int) $this->costCategoryId,
            'activity_participant_id' => $this->costParticipantId ? (int) $this->costParticipantId : null,
            'description' => $this->costDescription ?: null,
            'travel_mode' => $this->costTravelMode ?: null,
        ] + $this->costMethodData();
    }

    /** Saves the line being edited, or every line added in the popup together. */
    public function saveCost(ActivityEditor $editor): void
    {
        $line = $this->costId ? $this->activity->costs()->where('is_computed', false)->findOrFail($this->costId) : null;
        $lines = $line ? array_filter([$this->validatedCostLine()]) : $this->costLinesToSave();
        if (! $lines) {
            return;
        }

        if ($this->attempt(fn () => $this->saveCostLines(array_values($lines), fn (array $data) => $editor->savePlannedCost($this->activity, $data, Auth::user(), $line))) !== null) {
            $this->done(count($lines) === 1 ? 'Planned cost saved.' : count($lines).' cost lines saved.');
        }
    }

    public function removeCost(int $id, ActivityEditor $editor): void
    {
        $line = $this->activity->costs()->findOrFail($id);

        if ($this->attempt(fn () => $editor->removePlannedCost($line, Auth::user())) !== null) {
            $this->done('Cost line removed.');
        }
    }

    // ── An officer missing from the staff list (App\Livewire\Concerns\AddsMissingOfficers) ──

    /** Back to the picker with the new officers ticked. */
    protected function missingOfficersAdded(array $staffIds, ?string $search): void
    {
        $this->showAdd = true;
        $this->staffSearch = (string) $search;
        $this->selectedStaff = array_values(array_unique([...array_map('intval', $this->selectedStaff), ...$staffIds]));
    }

    public function render(ParticipantChecks $checks): View
    {
        $activity = $this->activity->load([
            'organisingDepartment', 'locations.county', 'locations.region', 'locations.constituency',
            'participants.staff', 'participants.department', 'participants.designation', 'costs.category', 'costs.participant.staff',
        ]);

        // Overlaps are flagged with the days, never named: the other activity may belong to another department.
        $overlapping = array_keys($checks->conflictsFor($activity));

        $candidates = collect();
        $unavailable = [];
        $pending = [];
        if ($this->showAdd) {
            $candidates = $this->candidates();
            $unavailable = $checks->unavailableStaff($activity->start_date, $activity->end_date, $activity->id);
            $pending = $checks->pendingStaff($activity->start_date, $activity->end_date, $activity->id);
        }

        return view('livewire.memos.team', [
            'a' => $activity,
            'editable' => $activity->status->isWithDepartment() && ! $activity->isWithPreparer(),
            'overlapping' => $overlapping,
            'candidates' => $candidates,
            'unavailable' => $unavailable,
            'pending' => $pending,
            'selectedIds' => array_map('intval', $this->selectedStaff),
            'roles' => ParticipantRole::cases(),
            'externalCategories' => ExternalCategory::cases(),
            'grades' => DsaRate::grades(),
            'travelModes' => TravelMode::cases(),
            'costCategories' => CostCategory::query()->active()->whereNotIn('code', DsaCalculator::COMPUTED_CODES)->ordered()->get(['id', 'name']),
            'rateUnits' => RateUnit::cases(),
            'dsaUnit' => RateUnit::forDsaBasis((string) app(AppSettings::class)->get('dsa_basis')),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'designations' => Designation::query()->active()->ordered()->get(['id', 'name']),
            'offices' => Office::query()->active()->ordered()->get(['id', 'name']),
            'selected' => $this->participantId ? $activity->participants->firstWhere('id', $this->participantId) : null,
        ])->title('Team and costs · '.$activity->reference);
    }
}

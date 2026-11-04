<?php

namespace App\Livewire\Activities;

use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityDocument;
use App\Models\ActivityType;
use App\Models\BudgetLine;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Region;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\BudgetPosition;
use App\Services\DocumentStore;
use App\Support\DsaPeriod;
use App\Support\FinancialYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * FRD PL-01..PL-04, PL-08: record a planned activity, or edit its details.
 * Also the activity memo form: a Head of Department prepares their own
 * department's memo here (department fixed to theirs), and the Office of the
 * CEO can name the Head of Department a memo is prepared on behalf of.
 */
#[Layout('layouts.admin')]
class Form extends Component
{
    use RunsActions;
    use WithFileUploads;

    public ?Activity $activity = null;

    /**
     * Inside the one-page memo builder (Memos\Compose): the details are the first steps of the page, so
     * saving keeps the Head on the page (the first save creates the draft) instead of redirecting.
     */
    public bool $embedded = false;

    /** Step 1 of the builder, "About the activity" (4 November 2026): for a Head of Department's memo every field is required. */
    public const ABOUT_FIELDS = ['title', 'activity_category_id', 'category_other', 'activity_type_id', 'purpose', 'justification', 'expected_outputs', 'risks', 'target_audience', 'notes'];

    /** Step 1 is complete, so step 2 (reference, dates and budget) is open. */
    public bool $aboutDone = false;

    public string $title = '';

    public string $purpose = '';

    public string $expected_outputs = '';

    public string $notes = '';

    public string $justification = '';

    public string $risks = '';

    /** @var list<array{heading: string, body: string}> sections the template does not cover */
    public array $memo_sections = [];

    public string $target_audience = '';

    public string $requested_budget = '';

    public string $responsible_hod_id = '';

    public string $responsible_hod_name = '';

    /** updated_at when the form was opened, so a save cannot silently overwrite someone else's (concurrent edits). */
    public string $loadedVersion = '';

    public string $activity_category_id = '';

    /** The category in words, when "Other (specify)" is chosen. */
    public string $category_other = '';

    public string $activity_type_id = '';

    public string $organising_department_id = '';

    public string $budget_line_id = '';

    public string $source_reference = '';

    public string $source_received_on = '';

    /** A Head of Department's memo: the date it is issued, printed on the memo as DATE. */
    public string $issued_on = '';

    public string $start_date = '';

    /** Expected completion date (4 November 2026): the days and nights are worked out from the two dates. */
    public string $end_date = '';

    public bool $count_externals_in_per_head = true;

    // First location and source document (create only).
    public string $region_id = '';

    public string $county_id = '';

    public string $constituency_id = '';

    public string $venue = '';

    public $memo = null;

    public function mount(?Activity $activity = null, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $user = Auth::user();
        $this->aboutDone = $this->isOffice() || ($activity?->exists && self::aboutComplete($activity));

        if ($activity?->exists) {
            abort_if($activity->status->isReadOnly(), 403, 'This activity is read only.');
            abort_unless($this->isOffice() || ($activity->isVisibleTo($user) && $activity->status->isWithDepartment()), 403, 'This memo is with the Office of the CEO and cannot be changed now.');
            $this->activity = $activity;
            $this->loadedVersion = (string) $activity->updated_at?->toIso8601String();
            // Optional columns are NULL in the database but strings here, so cast before filling.
            foreach (['title', 'purpose', 'expected_outputs', 'justification', 'risks', 'target_audience', 'requested_budget', 'notes', 'source_reference', 'activity_category_id', 'category_other', 'activity_type_id', 'organising_department_id', 'budget_line_id', 'responsible_hod_id', 'responsible_hod_name'] as $key) {
                $this->{$key} = (string) ($activity->{$key} ?? '');
            }
            $this->memo_sections = collect((array) $activity->memo_sections)->map(fn ($s) => ['heading' => (string) ($s['heading'] ?? ''), 'body' => (string) ($s['body'] ?? '')])->values()->all();
            $this->end_date = $activity->end_date->toDateString();
            $this->count_externals_in_per_head = (bool) $activity->count_externals_in_per_head;
            $this->source_received_on = $activity->source_received_on?->toDateString() ?? '';
            $this->issued_on = $activity->issued_on?->toDateString() ?? '';
            $this->start_date = $activity->start_date->toDateString();
        } else {
            abort_unless($this->isOffice() || $user->department_id, 403, 'Your account is not linked to a department. Ask the System Administrator.');
            $this->source_received_on = today()->toDateString();
            if (! $this->isOffice()) {
                $this->organising_department_id = (string) $user->department_id;
                $this->issued_on = today()->toDateString();
            }
        }
    }

    /** The team, costs or venue changed elsewhere on the builder page: the memo's own later save is not someone else's. */
    #[On('memo-changed')]
    #[On('team-changed')]
    public function memoChanged(): void
    {
        if ($this->activity) {
            $this->loadedVersion = (string) $this->activity->fresh()?->updated_at?->toIso8601String();
        }
    }

    /** Whether every step 1 field of a memo is filled in (the type only when its category has types). */
    public static function aboutComplete(Activity $activity): bool
    {
        foreach (['title', 'purpose', 'justification', 'expected_outputs', 'risks', 'target_audience', 'notes'] as $field) {
            if (trim((string) $activity->{$field}) === '') {
                return false;
            }
        }

        $category = $activity->category;

        return $category !== null
            && (! $category->requires_detail || trim((string) $activity->category_other) !== '')
            && ($activity->activity_type_id !== null || ActivityType::query()->active()->where('activity_category_id', $category->id)->doesntExist());
    }

    /** Step 1 is checked on its own; when it is complete, step 2 opens and the page moves to it. */
    public function continueToDates(): void
    {
        $this->validate(array_intersect_key($this->rules(), array_flip(self::ABOUT_FIELDS)));
        $this->aboutDone = true;
        $this->dispatch('about-done');
        $this->dispatch('builder-go', id: 'step-dates');
    }

    /** A Head of Department's memo needs every step 1 field; the Office of the CEO records from the source document. */
    private function requiresFullAbout(): bool
    {
        return ! $this->isOffice();
    }

    private function categoryHasTypes(): bool
    {
        return $this->activity_category_id !== '' && ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->exists();
    }

    /** The Office of the CEO (any department); otherwise a Head of Department (their own). */
    public function isOffice(): bool
    {
        return Auth::user()->can('activities.manage');
    }

    public function updatedOrganisingDepartmentId(): void
    {
        $this->responsible_hod_id = '';
    }

    public function updatedResponsibleHodId(string $value): void
    {
        $this->responsible_hod_name = $value !== '' ? (string) User::query()->whereKey($value)->value('name') : '';
    }

    /** Another section of the memo, after the template's own. */
    public function addSection(): void
    {
        if (count($this->memo_sections) < 10) {
            $this->memo_sections[] = ['heading' => '', 'body' => ''];
        }
    }

    public function removeSection(int $index): void
    {
        unset($this->memo_sections[$index]);
        $this->memo_sections = array_values($this->memo_sections);
    }

    public function updatedActivityCategoryId(): void
    {
        $this->activity_type_id = '';
        if (! $this->categoryRequiresDetail()) {
            $this->category_other = '';
        }
    }

    /** A completion date before a new start date is moved to the start date, so the dates never run backwards. */
    public function updatedStartDate(string $value): void
    {
        if ($value !== '' && ($this->end_date === '' || $this->end_date < $value)) {
            $this->end_date = $value;
        }
    }

    private function categoryRequiresDetail(): bool
    {
        return $this->activity_category_id !== '' && (bool) ActivityCategory::query()->whereKey($this->activity_category_id)->value('requires_detail');
    }

    /** Days from the two dates, both counted; null when either is missing or they run backwards. */
    private function dayCount(): ?int
    {
        if ($this->start_date === '' || $this->end_date === '') {
            return null;
        }

        try {
            $start = Carbon::parse($this->start_date)->startOfDay();
            $end = Carbon::parse($this->end_date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $end->lt($start) ? null : (int) $start->diffInDays($end) + 1;
    }

    public function updatedRegionId(): void
    {
        $this->county_id = '';
        $this->constituency_id = '';
    }

    public function updatedCountyId(): void
    {
        $this->constituency_id = '';
    }

    public function updatedConstituencyId(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->county_id = (string) $c->county_id;
            $this->region_id = (string) $c->region_id;
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $creating = ! $this->activity;
        $full = $this->requiresFullAbout();
        // Spaces alone do not fill a required field.
        $filled = $full ? ['regex:/\S/'] : [];

        return [
            'title' => ['required', 'string', 'max:255', ...$filled],
            'purpose' => ['required', 'string', 'max:5000', ...$filled],
            'expected_outputs' => [$full ? 'required' : 'nullable', 'string', 'max:5000', ...$filled],
            'justification' => [$full ? 'required' : 'nullable', 'string', 'max:5000', ...$filled],
            'risks' => [$full ? 'required' : 'nullable', 'string', 'max:5000', ...$filled],
            'memo_sections' => ['array', 'max:10'],
            'memo_sections.*.heading' => ['required', 'string', 'max:150'],
            'memo_sections.*.body' => ['required', 'string', 'max:5000'],
            'target_audience' => [$full ? 'required' : 'nullable', 'string', 'max:255', ...$filled],
            'requested_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'responsible_hod_id' => ['nullable', 'exists:users,id'],
            'responsible_hod_name' => ['nullable', 'string', 'max:255'],
            'notes' => [$full ? 'required' : 'nullable', 'string', 'max:5000', ...$filled],
            'activity_category_id' => ['required', 'exists:activity_categories,id'],
            'category_other' => $this->categoryRequiresDetail() ? ['required', 'string', 'max:150', 'regex:/\S/'] : ['nullable', 'string', 'max:150'],
            'activity_type_id' => [$full && $this->categoryHasTypes() ? 'required' : 'nullable', Rule::exists('activity_types', 'id')->where('activity_category_id', $this->activity_category_id ?: 0)],
            'organising_department_id' => ['required', 'exists:departments,id'],
            // Only a line of a budget the CEO has approved can be charged.
            'budget_line_id' => ['nullable', Rule::exists('budget_lines', 'id')->whereNull('deleted_at')->whereIn('department_budget_id', DepartmentBudget::query()->approved()->pluck('id')->all())],
            // A Head of Department's memo needs its reference and the date it is issued (25 October 2026);
            // a memo the Office of the CEO records keeps the date it was received.
            'source_reference' => $this->isOffice() ? ['nullable', 'string', 'max:100'] : ['required', 'string', 'max:100', 'regex:/\S/'],
            'source_received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'issued_on' => $this->isOffice() ? ['nullable', 'date'] : ['required', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date', function (string $attribute, mixed $value, \Closure $fail): void {
                if (($days = $this->dayCount()) !== null && $days > 120) {
                    $fail('An activity lasts at most 120 days.');
                }
            }],
            'count_externals_in_per_head' => ['boolean'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'county_id' => ['nullable', 'exists:counties,id'],
            'constituency_id' => ['nullable', 'exists:constituencies,id'],
            'venue' => ['nullable', 'string', 'max:255'],
            // On edit the property is present but null, so 'file' must not run; documents are attached on the activity page.
            'memo' => $creating ? ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['purpose' => 'purpose', 'source_reference' => 'memo reference', 'issued_on' => 'date issued', 'organising_department_id' => 'requesting department', 'activity_category_id' => 'category', 'category_other' => 'category (other)', 'end_date' => 'expected completion date', 'start_date' => 'planned start date', 'memo' => 'source document', 'justification' => 'introduction and justification', 'risks' => 'key risks', 'memo_sections.*.heading' => 'section heading', 'memo_sections.*.body' => 'section text', 'requested_budget' => 'estimated budget', 'target_audience' => 'participants and stakeholders', 'expected_outputs' => 'expected outcomes and deliverables', 'activity_type_id' => 'activity type', 'notes' => 'notes', 'responsible_hod_id' => 'Head of Department', 'responsible_hod_name' => 'Head of Department'];
    }

    public function save(ActivityEditor $editor, DocumentStore $documents): void
    {
        $data = $this->validate();
        $user = Auth::user();

        if ($data['responsible_hod_id'] && ! User::query()->role(User::ROLE_HOD)->whereKey($data['responsible_hod_id'])->where('department_id', $data['organising_department_id'])->exists()) {
            $this->addError('responsible_hod_id', 'Choose a Head of Department account of the requesting department.');

            return;
        }

        if ($this->activity && $this->activity->fresh()?->updated_at?->toIso8601String() !== $this->loadedVersion) {
            $this->toast('Someone else saved this memo after you opened it. Reload the page to see their changes, then make yours again.', 'error');

            return;
        }

        $data['days'] = $this->dayCount();
        $data['category_other'] = $this->categoryRequiresDetail() ? trim((string) $data['category_other']) : null;
        $fields = ['title', 'purpose', 'expected_outputs', 'justification', 'risks', 'memo_sections', 'target_audience', 'requested_budget', 'notes', 'activity_category_id', 'category_other', 'activity_type_id', 'organising_department_id',
            'budget_line_id', 'source_reference', 'start_date', 'days', 'count_externals_in_per_head'];
        $fields = $this->isOffice()
            ? [...$fields, 'source_received_on', 'responsible_hod_id', 'responsible_hod_name']
            : [...$fields, 'issued_on'];
        if (isset($data['source_reference'])) {
            $data['source_reference'] = trim((string) $data['source_reference']);
        }

        $details = collect($data)->only($fields)->map(fn ($v) => $v === '' || $v === [] ? null : $v)->all();

        $activity = $this->attempt(function () use ($editor, $documents, $details, $data, $user) {
            if ($this->activity) {
                return DB::transaction(function () use ($editor, $details, $data, $user) {
                    $activity = $editor->update($this->activity, $details, $user);

                    if ($activity->locations()->doesntExist() && ($data['county_id'] || $data['constituency_id'] || $data['region_id'] || $data['venue'])) {
                        $editor->addLocation($activity, collect($data)->only(['region_id', 'county_id', 'constituency_id', 'venue'])->map(fn ($v) => $v ?: null)->all(), $user);
                    }

                    return $activity;
                });
            }

            return DB::transaction(function () use ($editor, $documents, $details, $data, $user) {
                $activity = $editor->create($details, $user);

                if ($data['county_id'] || $data['constituency_id'] || $data['region_id'] || $data['venue']) {
                    $editor->addLocation($activity, collect($data)->only(['region_id', 'county_id', 'constituency_id', 'venue'])->map(fn ($v) => $v ?: null)->all(), $user);
                }

                if ($this->memo) {
                    $documents->store($activity, $this->memo, ActivityDocument::KIND_MEMO, $user);
                }

                return $activity;
            });
        });

        if ($activity instanceof Activity && $this->embedded) {
            $created = ! $this->activity;
            $this->activity = $activity;
            $this->loadedVersion = (string) $activity->fresh()?->updated_at?->toIso8601String();
            $this->reset('memo');
            $this->dispatch('memo-saved', id: $activity->id, created: $created);
            $this->toast($created ? 'Draft saved as '.$activity->reference.'. Next: the venue, team and costs below.' : 'Draft saved.');

            return;
        }

        if ($activity instanceof Activity) {
            if ($this->isOffice()) {
                session()->flash('notify', ['type' => 'success', 'message' => $this->activity ? 'Activity updated.' : 'Activity recorded as a draft. Add the team and costs next.']);
                $this->redirectRoute('activities.show', $activity, navigate: true);
            } else {
                session()->flash('notify', ['type' => 'success', 'message' => $this->activity ? 'Memo saved.' : 'Memo saved as a draft. Attach the signed memo, then submit it for review.']);
                $this->redirectRoute('memos.show', $activity, navigate: true);
            }
        }
    }

    /**
     * The steps of the Office of the CEO's record page, in the same frame as the memo builder (4 November 2026).
     * The team and costs are added on the activity page once it is saved.
     *
     * @return list<array{id: string, number: int, title: string, done: bool, locked: bool}>
     */
    private function pageSteps(): array
    {
        $saved = (bool) $this->activity?->exists;
        $filled = fn (string ...$fields): bool => collect($fields)->every(fn (string $f) => trim((string) $this->{$f}) !== '');

        return [
            ['id' => 'step-details', 'number' => 1, 'title' => 'About the activity', 'done' => $filled('title', 'activity_category_id', 'purpose'), 'locked' => false],
            ['id' => 'step-source', 'number' => 2, 'title' => 'Source', 'done' => $filled('organising_department_id'), 'locked' => false],
            ['id' => 'step-dates', 'number' => 3, 'title' => 'Dates and location', 'done' => $filled('start_date', 'end_date'), 'locked' => false],
            ['id' => 'step-budget', 'number' => 4, 'title' => 'Budget', 'done' => $filled('requested_budget') || $filled('budget_line_id'), 'locked' => false],
            ['id' => 'step-team', 'number' => 5, 'title' => 'Team and costs', 'done' => $saved && $this->activity->participants()->exists(), 'locked' => ! $saved],
        ];
    }

    public function render(BudgetPosition $budget): View
    {
        $start = rescue(fn () => $this->start_date ? Carbon::parse($this->start_date) : null, null, false);
        $days = $this->dayCount();
        $fy = $start ? FinancialYear::for($start)->label() : FinancialYear::current()->label();

        return view('livewire.activities.form', [
            'period' => $start && $days ? DsaPeriod::of($start, $days) : null,
            'datesBackwards' => $start && $this->end_date !== '' && $days === null,
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name', 'requires_detail']),
            'needsCategoryDetail' => $this->categoryRequiresDetail(),
            'types' => $this->activity_category_id ? ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'heads' => $this->isOffice() && $this->organising_department_id
                ? User::query()->role(User::ROLE_HOD)->where('department_id', $this->organising_department_id)->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active'])
                : collect(),
            'office' => $this->isOffice(),
            'pageSteps' => $this->embedded ? [] : $this->pageSteps(),
            'fullAbout' => $this->requiresFullAbout(),
            'budget' => $this->organising_department_id ? $budget->forDepartment(FinancialYear::fromLabel($fy), (int) $this->organising_department_id) : null,
            'budgetLines' => BudgetLine::query()->approved()->where('financial_year', $fy)->when($this->organising_department_id, fn ($q, $id) => $q->where('department_id', $id))->orderBy('name')->get(),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->when($this->region_id, fn ($q, $id) => $q->whereHas('constituencies', fn ($c) => $c->where('region_id', $id)))->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->title(match (true) {
            ! $this->isOffice() => $this->activity ? 'Edit memo' : 'Create activity memo',
            default => $this->activity ? 'Edit activity' : 'Record activity',
        });
    }
}

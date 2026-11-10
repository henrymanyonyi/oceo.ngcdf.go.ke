<?php

namespace App\Livewire;

use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Http\Controllers\PrivacyStatementController;
use App\Livewire\Activities\Form;
use App\Livewire\Concerns\EntersCostLines;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityType;
use App\Models\Constituency;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Services\DelegatedMemos;
use App\Services\DsaCalculator;
use App\Services\MemoDocument;
use App\Services\PrivacyConsentService;
use App\Services\StaffVisibility;
use App\Support\DsaPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The page an officer opens from a Head of Department's memo request
 * (App\Services\DelegatedMemos). It shows nothing but this one memo: first a
 * code sent to the officer's email or mobile, then the memo's details, team,
 * costs and supporting documents, a preview of the generated memo, and
 * submission to the Head. The token is re-checked on every request, and the
 * officer must have agreed to the Data Privacy Statement.
 */
#[Layout('layouts.public', ['wide' => true])]
#[Title('Prepare an activity memo')]
class MemoRequest extends Component
{
    use EntersCostLines;
    use WithFileUploads;

    #[Locked]
    public string $token = '';

    public string $code = '';

    /** @var list<string> where the last code went, masked */
    public array $codeSentTo = [];

    public string $title = '';

    public string $purpose = '';

    public string $expected_outputs = '';

    public string $justification = '';

    public string $risks = '';

    public string $target_audience = '';

    /** The memo's "Request for approval" as typed; empty keeps the standard wording. */
    public string $approval_request = '';

    public string $notes = '';

    public string $activity_category_id = '';

    /** The category in words, when "Other (specify)" is chosen. */
    public string $category_other = '';

    public string $activity_type_id = '';

    public string $start_date = '';

    /** Expected completion date: the days and nights are worked out from the two dates. */
    public string $end_date = '';

    public string $county_id = '';

    public string $constituency_id = '';

    public string $venue = '';

    // Team
    public string $staffSearch = '';

    /** @var list<int> */
    public array $picked = [];

    public string $role = 'member';

    /** The role in words, when Other (specify) is chosen. */
    public string $roleOther = '';

    // A cost line (how its amount is worked out: App\Livewire\Concerns\EntersCostLines)
    public string $costCategory = '';

    public string $costParticipant = '';

    public string $costDescription = '';

    public $document = null;

    public bool $confirmSubmit = false;

    /** Step 1, "About the activity", is complete (every field, as on the Head's own memo), so step 2 is open. */
    public bool $aboutDone = false;

    /** @var list<string> */
    public array $warnings = [];

    public ?string $done = null;

    public function mount(string $token, DelegatedMemos $memos): void
    {
        $this->token = $token;
        $link = $memos->find($token);

        if ($link?->isUsable() && ! app(PrivacyConsentService::class)->visitorHasConsented(request())) {
            session()->put(PrivacyStatementController::RETURN_TO_KEY, route('memo-request.show', $token));
            $this->redirectRoute('privacy.statement');

            return;
        }

        if ($link && $memos->isVerified($link)) {
            $this->fill($this->detailsOf($link->memo));
            $this->aboutDone = $link->memo !== null && Form::aboutComplete($link->memo);
        }
    }

    private function link(): SubmissionLink
    {
        return app(DelegatedMemos::class)->find($this->token) ?? abort(404);
    }

    /** Runs an action on the link, showing its message on failure. */
    private function act(callable $action, ?string $done = null, string $field = 'form'): bool
    {
        try {
            $action();
            if ($done) {
                $this->dispatch('notify', message: $done, type: 'success');
            }

            return true;
        } catch (ActivityWorkflowException|AuthorizationException $e) {
            $this->addError($field, $e->getMessage());

            return false;
        }
    }

    // ── Confirming who they are ──────────────────────────────────────

    public function sendCode(DelegatedMemos $memos): void
    {
        $this->act(function () use ($memos) {
            $this->codeSentTo = $memos->sendCode($this->link());
        }, field: 'code');
    }

    public function verify(DelegatedMemos $memos): void
    {
        $this->validate(['code' => ['required', 'digits:6']], [], ['code' => 'code']);

        if ($this->act(fn () => $memos->verify($this->link(), $this->code), field: 'code')) {
            $this->code = '';
            $memo = $this->link()->memo;
            $this->fill($this->detailsOf($memo));
            $this->aboutDone = $memo !== null && Form::aboutComplete($memo);
        }
    }

    // ── The memo ─────────────────────────────────────────────────────

    public function updatedCountyId(): void
    {
        $this->constituency_id = '';
    }

    public function updatedEndDate(): void
    {
        $this->saveDates();
    }

    public function updatedStartDate(string $value): void
    {
        if ($value !== '' && ($this->end_date === '' || $this->end_date < $value)) {
            $this->end_date = $value;
        }
        $this->saveDates();
    }

    /** Days from the two dates, both counted; null when either is missing or they run backwards. */
    private function dayCount(): ?int
    {
        $start = rescue(fn () => $this->start_date !== '' ? Carbon::parse($this->start_date)->startOfDay() : null, null, false);
        $end = rescue(fn () => $this->end_date !== '' ? Carbon::parse($this->end_date)->startOfDay() : null, null, false);

        return $start && $end && $end->gte($start) ? (int) $start->diffInDays($end) + 1 : null;
    }

    /** @return array<string, list<mixed>> */
    private function dateRules(): array
    {
        return [
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date', function (string $attribute, mixed $value, \Closure $fail): void {
                if (($this->dayCount() ?? 0) > 120) {
                    $fail('An activity lasts at most 120 days.');
                }
            }],
        ];
    }

    private function categoryRequiresDetail(): bool
    {
        return $this->activity_category_id !== '' && (bool) ActivityCategory::query()->whereKey($this->activity_category_id)->value('requires_detail');
    }

    /** Once the memo exists, a change of dates is saved at once so the DSA lines and the total are recalculated. */
    private function saveDates(): void
    {
        $link = $this->link();
        if (! $link->activity_id) {
            return;
        }

        $this->validate($this->dateRules(), [], ['end_date' => 'expected completion date', 'start_date' => 'planned start date']);

        $this->act(fn () => app(DelegatedMemos::class)->saveDates($link, $this->start_date, (int) $this->dayCount()), 'Dates saved. Allowances (DSA) and the total have been recalculated.', 'end_date');
    }

    /**
     * Step 1: every field about the activity is required, as on the Head of Department's own memo (4 November 2026).
     *
     * @return array<string, list<mixed>>
     */
    private function aboutRules(): array
    {
        $filled = ['regex:/\S/'];
        $hasTypes = $this->activity_category_id !== '' && ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->exists();

        return [
            'title' => ['required', 'string', 'max:255', ...$filled],
            'activity_category_id' => ['required', 'exists:activity_categories,id'],
            'category_other' => $this->categoryRequiresDetail() ? ['required', 'string', 'max:150', ...$filled] : ['nullable', 'string', 'max:150'],
            'activity_type_id' => [$hasTypes ? 'required' : 'nullable', Rule::exists('activity_types', 'id')->where('activity_category_id', $this->activity_category_id ?: 0)],
            'purpose' => ['required', 'string', 'max:5000', ...$filled],
            'justification' => ['required', 'string', 'max:5000', ...$filled],
            'expected_outputs' => ['required', 'string', 'max:5000', ...$filled],
            'risks' => ['required', 'string', 'max:5000', ...$filled],
            'target_audience' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'approval_request' => ['required', 'string', 'max:5000', ...$filled],
        ];
    }

    /** @return array<string, string> */
    private function aboutAttributes(): array
    {
        return ['purpose' => 'purpose', 'justification' => 'introduction and justification', 'activity_category_id' => 'category', 'category_other' => 'category (other)', 'activity_type_id' => 'activity type', 'expected_outputs' => 'expected outcomes and deliverables', 'risks' => 'key risks', 'target_audience' => 'participants and stakeholders', 'notes' => 'notes', 'approval_request' => 'request for approval'];
    }

    /** Step 1 is checked on its own; when complete, step 2 opens and the page moves to it. */
    public function continueToDates(): void
    {
        $this->validate($this->aboutRules(), [], $this->aboutAttributes());
        $this->aboutDone = true;
        $this->dispatch('builder-go', id: 'step-dates');
    }

    public function save(DelegatedMemos $memos): void
    {
        $data = $this->validate([
            ...$this->aboutRules(),
            ...$this->dateRules(),
            // Every part of the location is required (10 November 2026); the region comes from the constituency.
            'county_id' => ['required', 'exists:counties,id'],
            'constituency_id' => ['required', Rule::exists('constituencies', 'id')->where('county_id', $this->county_id ?: 0)],
            'venue' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ], [], [...$this->aboutAttributes(), 'end_date' => 'expected completion date']);
        $data['days'] = $this->dayCount();
        $data['category_other'] = $this->categoryRequiresDetail() ? trim((string) $data['category_other']) : null;
        $first = ! $this->link()->activity_id;

        if ($this->act(fn () => $memos->saveDetails($this->link(), $data), $first ? 'Draft saved. Next: the team and costs below.' : 'Saved.') && $first) {
            $this->dispatch('builder-go', id: 'step-team');
        }
    }

    public function updatedActivityCategoryId(): void
    {
        $this->activity_type_id = '';
        if (! $this->categoryRequiresDetail()) {
            $this->category_other = '';
        }
    }

    /**
     * The steps for the rail, in order.
     *
     * @return list<array{id: string, number: int, title: string, done: bool, locked: bool}>
     */
    private function steps(?Activity $memo, array $issues): array
    {
        $saved = (bool) $memo;

        return [
            ['id' => 'step-details', 'number' => 1, 'title' => 'About the activity', 'done' => $this->aboutDone, 'locked' => false],
            ['id' => 'step-dates', 'number' => 2, 'title' => 'Dates, venue and budget', 'done' => $saved, 'locked' => ! $this->aboutDone],
            ['id' => 'step-team', 'number' => 3, 'title' => 'Team', 'done' => $saved && $memo->participants->isNotEmpty(), 'locked' => ! $saved],
            ['id' => 'step-costs', 'number' => 4, 'title' => 'Costs', 'done' => $saved && $memo->costs->isNotEmpty(), 'locked' => ! $saved],
            ['id' => 'step-review', 'number' => 5, 'title' => 'Check and submit', 'done' => $saved && collect($issues)->where('blocking', true)->isEmpty(), 'locked' => ! $saved],
        ];
    }

    public function addOfficers(DelegatedMemos $memos): void
    {
        Validator::make(['picked' => $this->picked, 'role' => $this->role, 'roleOther' => $this->roleOther], [
            'picked' => ['required', 'array', 'min:1'],
            'picked.*' => ['integer', 'exists:staff,id'],
            'role' => ['required', 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'))],
            'roleOther' => $this->role === ParticipantRole::Other->value ? ['required', 'string', 'max:100', 'regex:/\S/'] : ['nullable', 'string', 'max:100'],
        ], ['picked.required' => 'Tick the officers to add.', 'roleOther.required' => 'Say what their role is.'])->validate();

        $this->act(function () use ($memos) {
            $this->warnings = $memos->addOfficers($this->link(), $this->picked, ParticipantRole::from($this->role), $this->roleOther);
            $this->reset(['picked', 'staffSearch', 'roleOther']);
        }, 'Added to the team. Allowances (DSA) are calculated for you.', 'picked');
    }

    public function removeParticipant(DelegatedMemos $memos, int $id): void
    {
        $this->act(fn () => $memos->removeParticipant($this->link(), $id), 'Removed from the team.');
    }

    /** The line typed on the page, checked, as ActivityEditor::savePlannedCost() takes it (EntersCostLines). */
    protected function validatedCostLine(): ?array
    {
        $this->validate([
            // DSA and external participants' allowances are calculated, never typed in.
            'costCategory' => ['required', Rule::exists('cost_categories', 'id')->where(fn ($q) => $q->whereNotIn('code', DsaCalculator::COMPUTED_CODES))],
            'costParticipant' => ['nullable', 'integer'],
            'costDescription' => ['nullable', 'string', 'max:255'],
            ...$this->costMethodRules(),
        ], $this->costMethodMessages(), ['costCategory' => 'cost type'] + $this->costMethodAttributes());

        $memo = $this->link()->memo;
        if ($this->costParticipant !== '' && ! $memo?->participants()->whereKey((int) $this->costParticipant)->exists()) {
            $this->addError('costParticipant', 'Choose someone on the team, or the whole activity.');

            return null;
        }

        return [
            'cost_category_id' => (int) $this->costCategory,
            'activity_participant_id' => $this->costParticipant !== '' ? (int) $this->costParticipant : null,
            'description' => $this->costDescription ?: null,
        ] + $this->costMethodData();
    }

    /** Adds the line typed, then clears the fields for the next one. */
    public function addCost(DelegatedMemos $memos): void
    {
        $data = $this->validatedCostLine();
        if ($data === null) {
            return;
        }

        $this->act(function () use ($memos, $data) {
            $memos->saveCost($this->link(), $data);
            $this->reset(['costCategory', 'costParticipant', 'costDescription']);
            $this->resetCostMethod();
        }, 'Cost line added. Add the next one, or carry on.', 'costAmount');
    }

    public function removeCost(DelegatedMemos $memos, int $id): void
    {
        $this->act(fn () => $memos->removeCost($this->link(), $id), 'Cost line removed.');
    }

    public function updatedDocument(DelegatedMemos $memos): void
    {
        $this->validate(['document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')]], [], ['document' => 'document']);

        if ($this->act(fn () => $memos->attach($this->link(), $this->document), 'Supporting document attached.', 'document')) {
            $this->reset('document');
        }
    }

    public function submit(DelegatedMemos $memos): void
    {
        $this->confirmSubmit = false;
        if ($this->act(fn () => $memos->submit($this->link()))) {
            $this->done = 'Submitted to your Head of Department, who will review it. You will be told by email or SMS if it comes back to you.';
        }
    }

    /** @return array<string, mixed> */
    private function detailsOf(?Activity $memo): array
    {
        if (! $memo) {
            return [];
        }

        $location = $memo->locations()->first();

        return [
            'title' => (string) $memo->title,
            'purpose' => (string) $memo->purpose,
            'expected_outputs' => (string) $memo->expected_outputs,
            'justification' => (string) $memo->justification,
            'risks' => (string) $memo->risks,
            'target_audience' => (string) $memo->target_audience,
            'notes' => (string) $memo->notes,
            'approval_request' => (string) $memo->approval_request,
            'activity_category_id' => (string) $memo->activity_category_id,
            'category_other' => (string) $memo->category_other,
            'activity_type_id' => (string) $memo->activity_type_id,
            'start_date' => $memo->start_date?->toDateString() ?? '',
            'end_date' => $memo->end_date?->toDateString() ?? '',
            'county_id' => (string) $location?->county_id,
            'constituency_id' => (string) $location?->constituency_id,
            'venue' => (string) $location?->venue,
        ];
    }

    public function render(DelegatedMemos $memos, MemoDocument $document): View
    {
        $link = $memos->find($this->token);
        $verified = $link && $link->isUsable() && $memos->isVerified($link);
        $memo = $verified && $link->activity_id ? Activity::query()->with(['participants.staff', 'costs.category', 'costs.participant.staff', 'documents', 'locations'])->find($link->activity_id) : null;
        $editable = $verified && (! $memo || $memo->isWithPreparer());
        $search = trim($this->staffSearch);

        $issues = $memo && $editable ? array_values(array_filter($document->issues($memo), fn (array $i) => ! $i['for_head'])) : [];

        return view('livewire.memo-request', [
            'steps' => $this->steps($memo, $issues),
            'fullAbout' => true,
            'link' => $link?->loadMissing(['department', 'creator']),
            'usable' => $link?->isUsable() ?? false,
            'verified' => $verified,
            'memo' => $memo,
            'editable' => $editable,
            'returned' => $memo?->status === ActivityStatus::ReturnedToPreparer,
            'issues' => $issues,
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name', 'requires_detail']),
            'needsCategoryDetail' => $this->categoryRequiresDetail(),
            'types' => $this->activity_category_id ? ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'counties' => County::query()->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'costCategories' => CostCategory::query()->active()->whereNotIn('code', DsaCalculator::COMPUTED_CODES)->ordered()->get(['id', 'name']),
            'roles' => ParticipantRole::cases(),
            'matches' => $memo && $editable && mb_strlen($search) >= 2
                ? app(StaffVisibility::class)->forPicker(Staff::query()->active(), null, $search)->whereNotIn('id', $memo->participants->pluck('staff_id')->filter())->with(['department', 'designation'])->orderBy('name')->limit(20)->get()
                : collect(),
            'period' => ($days = $this->dayCount()) ? DsaPeriod::of(Carbon::parse($this->start_date), $days) : null,
        ]);
    }
}

<?php

namespace App\Livewire\Memos;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Livewire\Activities\Form;
use App\Livewire\Concerns\RunsActions;
use App\Livewire\Concerns\SubmitsMemo;
use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Region;
use App\Services\AccessLogger;
use App\Services\ActivityEditor;
use App\Services\BudgetPosition;
use App\Services\DocumentStore;
use App\Services\MemoDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The one-page memo builder (4 November 2026): a Head of Department prepares a memo from start to
 * submission on one page, in numbered steps, saving the draft as they go: (1) about the activity and
 * (2) reference, dates and budget (Activities\Form, embedded), (3) venue and location (here), (4) team
 * and (5) costs (Memos\Team, embedded), and (6) review, attachments and submit (here). The first save
 * creates the draft and opens the later steps without leaving the page.
 *
 * Every change still goes through ActivityEditor / ActivityLifecycle, which check the user's rights.
 */
#[Layout('layouts.admin')]
class Compose extends Component
{
    use RunsActions;
    use SubmitsMemo;
    use WithFileUploads;

    public ?Activity $activity = null;

    /** Step 1 is complete (Activities\Form::continueToDates), so step 2 is open. */
    public bool $aboutDone = false;

    public string $locRegion = '';

    public string $locCounty = '';

    public string $locConstituency = '';

    public string $locVenue = '';

    public $document = null;

    public function mount(AccessLogger $log, ?Activity $activity = null): void
    {
        $user = Auth::user();

        // The Office of the CEO records activities on its own pages. (A plain HTTP redirect: inside mount, redirect() is Livewire's.)
        if ($user->can('activities.manage')) {
            abort(new RedirectResponse(route($activity?->exists ? 'activities.edit' : 'activities.create', $activity?->exists ? $activity : [])));
        }

        if (! $activity?->exists) {
            abort_unless($user->department_id, 403, 'Your account is not linked to a department. Ask the System Administrator.');

            return;
        }

        abort_if($activity->status->isReadOnly(), 403, 'This activity is read only.');
        abort_unless($activity->isVisibleTo($user) && $activity->status->isWithDepartment(), 403, 'This memo is with the Office of the CEO and cannot be changed now.');

        if ($activity->isWithPreparer()) {
            session()->flash('notify', ['type' => 'warning', 'message' => 'An officer is preparing this memo. You can change it once they send it to you.']);
            abort(new RedirectResponse(route('memos.show', $activity)));
        }

        $this->activity = $activity;
        $this->aboutDone = Form::aboutComplete($activity);
        $log->log(AccessLogger::VIEW, $activity, ['page' => 'builder']);
    }

    /** Steps 1 and 2 were saved; the first save created the draft, so the later steps open. */
    #[On('memo-saved')]
    public function memoSaved(int $id, bool $created = false): void
    {
        $activity = Activity::query()->findOrFail($id);
        abort_unless($activity->isVisibleTo(Auth::user()), 403);
        $this->activity = $activity;

        if ($created) {
            $url = route('memos.edit', $activity);
            $this->js("history.replaceState(history.state, '', '{$url}')");
            $this->dispatch('builder-go', id: 'step-place');
        }
    }

    #[On('about-done')]
    public function markAboutDone(): void
    {
        $this->aboutDone = true;
    }

    /** The team or costs changed (Memos\Team): the progress, totals and checklist are drawn again. */
    #[On('team-changed')]
    public function teamChanged(): void
    {
        $this->activity?->refresh();
    }

    // ── Step 3: venue and location ─────────────────────────────────────

    public function updatedLocCounty(): void
    {
        $this->locConstituency = '';
    }

    public function updatedLocConstituency(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->locCounty = (string) $c->county_id;
            $this->locRegion = (string) $c->region_id;
        }
    }

    /** MD-04: a location; the county decides whether DSA applies. */
    public function addLocation(ActivityEditor $editor): void
    {
        $this->validate([
            'locRegion' => ['nullable', 'exists:regions,id'],
            'locCounty' => ['required_without_all:locVenue,locRegion', 'nullable', 'exists:counties,id'],
            'locConstituency' => ['nullable', 'exists:constituencies,id'],
            'locVenue' => ['nullable', 'string', 'max:255'],
        ], ['locCounty.required_without_all' => 'Choose the county, or at least give the venue.'], ['locCounty' => 'county', 'locVenue' => 'venue']);

        if ($this->attempt(fn () => $editor->addLocation($this->activity, [
            'region_id' => $this->locRegion ?: null,
            'county_id' => $this->locCounty ?: null,
            'constituency_id' => $this->locConstituency ?: null,
            'venue' => $this->locVenue ?: null,
        ], Auth::user()), 'Location added.')) {
            $this->reset(['locRegion', 'locCounty', 'locConstituency', 'locVenue']);
            $this->changed();
        }
    }

    public function removeLocation(int $id, ActivityEditor $editor): void
    {
        $location = $this->activity->locations()->findOrFail($id);

        if ($this->attempt(fn () => $editor->removeLocation($location, Auth::user()), 'Location removed.')) {
            $this->changed();
        }
    }

    // ── Step 6: attachments ────────────────────────────────────────────

    public function uploadDocument(ActivityEditor $editor, DocumentStore $store): void
    {
        $this->validate(['document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')]], [], ['document' => 'document']);

        $done = $this->attempt(function () use ($editor, $store) {
            $editor->authorizeDetails($this->activity, Auth::user());

            return DB::transaction(function () use ($store) {
                $kind = $this->activity->documents()->where('kind', ActivityDocument::KIND_MEMO)->exists() ? ActivityDocument::KIND_OTHER : ActivityDocument::KIND_MEMO;

                return $store->store($this->activity, $this->document, $kind, Auth::user());
            });
        }, 'Document attached.');

        if ($done) {
            $this->reset('document');
            $this->changed();
        }
    }

    /** The memo changed here: the embedded form and team pick up the new figures (DSA follows the county). */
    private function changed(): void
    {
        $this->activity->refresh();
        $this->dispatch('memo-changed');
    }

    protected function memoSubmitted(string $message): void
    {
        session()->flash('notify', ['type' => 'success', 'message' => $message]);
        $this->redirectRoute('memos.show', $this->activity, navigate: true);
    }

    /**
     * The steps for the progress rail, in order.
     *
     * @return list<array{id: string, number: int, title: string, done: bool, locked: bool}>
     */
    private function steps(?Activity $a, array $issues): array
    {
        $saved = (bool) $a;

        return [
            ['id' => 'step-details', 'number' => 1, 'title' => 'About the activity', 'done' => $this->aboutDone, 'locked' => false],
            ['id' => 'step-dates', 'number' => 2, 'title' => 'Reference, dates and budget', 'done' => $saved && $a->source_reference && $a->issued_on, 'locked' => ! $this->aboutDone],
            ['id' => 'step-place', 'number' => 3, 'title' => 'Venue and location', 'done' => $saved && $a->locations->whereNotNull('county_id')->isNotEmpty(), 'locked' => ! $saved],
            ['id' => 'step-team', 'number' => 4, 'title' => 'Team', 'done' => $saved && $a->participants->isNotEmpty(), 'locked' => ! $saved],
            ['id' => 'step-costs', 'number' => 5, 'title' => 'Costs', 'done' => $saved && $a->costs->isNotEmpty(), 'locked' => ! $saved],
            ['id' => 'step-review', 'number' => 6, 'title' => 'Review and submit', 'done' => $saved && collect($issues)->where('blocking', true)->isEmpty(), 'locked' => ! $saved],
        ];
    }

    public function render(MemoDocument $memoDocument, BudgetPosition $budget): View
    {
        $a = $this->activity?->load(['locations.region', 'locations.county', 'locations.constituency', 'documents', 'participants', 'costs', 'organisingDepartment']);
        $issues = $a ? $memoDocument->issues($a) : [];

        return view('livewire.memos.compose', [
            'a' => $a,
            'issues' => $issues,
            'steps' => $this->steps($a, $issues),
            'withHod' => $a?->status === ActivityStatus::WithHod,
            'budget' => $a?->organising_department_id ? $budget->forActivity($a) : null,
            'offices' => ReviewOffice::cases(),
            'designation' => Auth::user()->signingDesignation(),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->when($this->locRegion, fn ($q, $id) => $q->whereHas('constituencies', fn ($c) => $c->where('region_id', $id)))->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->locCounty ? Constituency::query()->where('county_id', $this->locCounty)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->title($a ? 'Edit '.$a->reference : 'Create activity memo');
    }
}

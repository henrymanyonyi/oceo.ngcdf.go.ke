<?php

namespace App\Livewire\Concerns;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Enums\SigningAction;
use App\Enums\SubmissionRoute;
use App\Services\ActivityLifecycle;
use App\Services\ReportCompliance;
use Illuminate\Support\Facades\Auth;

/**
 * The Head of Department's Submit / Resubmit step (ActivityLifecycle::submit), shared by the memo
 * page (Memos\Show) and the one-page memo builder (Memos\Compose). The popup is
 * resources/views/livewire/memos/partials/submit-modal.blade.php. The using component has
 * `public Activity $activity` (or ?Activity) and RunsActions.
 */
trait SubmitsMemo
{
    use ConfirmsSigning;

    public bool $showSubmit = false;

    /** The review office for the COS/ACOS route. */
    public string $office = 'assistant_chief_of_staff';

    /** How the Head sends the memo on (App\Enums\SubmissionRoute): through the COS/ACOS office, or directly to the CEO. */
    public string $route = 'review';

    public string $note = '';

    /** The Head signs as acting ("Ag." before their designation) on this submission. */
    public bool $acting = false;

    public function openSubmit(): void
    {
        $this->office = ($this->activity->review_office ?? ReviewOffice::AssistantChiefOfStaff)->value;
        // A memo returned for correction goes back along the route it came; a new one starts on the review route.
        $this->route = ($this->activity->status === ActivityStatus::ReturnedToHod ? $this->activity->route() : SubmissionRoute::Review)->value;
        $this->note = '';
        $this->acting = (bool) $this->activity->hod_acting;
        $this->resetSigning();
        $this->resetErrorBag();
        $this->showSubmit = true;
    }

    public function submit(ActivityLifecycle $lifecycle): void
    {
        $this->validate([
            'route' => ['required', 'in:'.implode(',', array_column(SubmissionRoute::cases(), 'value'))],
            'office' => ['required', 'in:'.implode(',', array_column(ReviewOffice::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
            'acting' => ['boolean'],
        ], [], ['note' => 'note', 'route' => 'route']);

        $resubmitting = $this->activity->status === ActivityStatus::ReturnedToHod;

        // The Head signs the memo they submit: password and code first (ActivityLifecycle checks it again).
        if (! $this->passesSigning($this->activity, SigningAction::Prepared)) {
            return;
        }

        if ($this->attempt(fn () => $lifecycle->submit($this->activity, Auth::user(), SubmissionRoute::from($this->route), ReviewOffice::from($this->office), $this->note, $this->acting)) === null) {
            return;
        }

        $this->showSubmit = false;
        $this->activity->refresh();
        $this->memoSubmitted(match (true) {
            $this->activity->isDirectToCeo() => ($resubmitting ? 'Resubmitted' : 'Submitted').' directly to the CEO, who has been told.',
            $resubmitting => 'Resubmitted. The reviewer has been told.',
            default => 'Submitted for review. The '.$this->activity->review_office?->label().' has been told.',
        });
    }

    /**
     * The department's overdue activity reports that hold this memo's first submission back
     * (App\Services\ReportCompliance), for the popup. Empty once they are in or the CEO allowed it.
     *
     * @return list<array<string, mixed>>
     */
    public function overdueReports(): array
    {
        $compliance = app(ReportCompliance::class);

        return $this->activity && $compliance->applies($this->activity) ? $compliance->describe($this->activity) : [];
    }

    /** After a successful submission; the memo page stays, the builder moves on to the memo page. */
    protected function memoSubmitted(string $message): void
    {
        $this->toast($message);
    }
}

<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Enums\ReviewRecommendation;
use App\Enums\SubmissionRoute;
use App\Livewire\Activities\Show as OfficeShow;
use App\Livewire\Memos\Show as MemoShow;
use App\Models\Activity;
use App\Models\ActivityStatusHistory;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\MemoDocument;
use App\Services\MemoTracker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->acos = assistant();
    $this->cos = chiefOfStaff();
    $this->ceo = ceo();
    $this->memo = departmentMemo($this->hod);
});

/** @return list<string> */
function kindsFor(User $user): array
{
    return $user->fresh()->notifications->pluck('data.kind')->all();
}

function lastHistory(Activity $memo): ActivityStatusHistory
{
    return ActivityStatusHistory::query()->where('activity_id', $memo->id)->latest('id')->first();
}

it('sends a memo directly to the CEO, recording the route, who sent it and when, and telling only the CEO', function () {
    $this->lifecycle->submitDirectToCeo($this->memo, $this->hod, 'Urgent, for the CEO.');

    $memo = $this->memo->fresh();
    $history = lastHistory($memo);
    expect($memo->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($memo->submission_route)->toBe(SubmissionRoute::Direct)
        ->and($memo->review_office)->toBeNull()
        ->and($memo->currentHolder())->toBe('Chief Executive Officer')
        ->and($history->from_status)->toBe(ActivityStatus::Draft)
        ->and($history->user_id)->toBe($this->hod->id)
        ->and($history->created_at)->not->toBeNull()
        ->and($history->reason)->toBe('Submitted directly to the CEO: Urgent, for the CEO.')
        ->and(kindsFor($this->ceo))->toContain('awaiting_decision')
        ->and(kindsFor($this->cos))->toBe([])
        ->and(kindsFor($this->acos))->toBe([]);

    // The CEO's queue has it; the reviewers' "forwarded" tab and their pending count do not, their read-only "direct" tab does.
    $tracker = app(MemoTracker::class);
    expect($tracker->query($this->ceo, 'awaiting')->pluck('id')->all())->toBe([$memo->id])
        ->and($tracker->query($this->cos, 'forwarded')->count())->toBe(0)
        ->and($tracker->query($this->cos, 'direct')->pluck('id')->all())->toBe([$memo->id])
        ->and($tracker->actionCount($this->cos))->toBe(0)
        ->and($tracker->actionCount($this->ceo))->toBe(1);
});

it('lets the Head choose the route on the memo page', function () {
    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $this->memo])
        ->call('openSubmit')->assertSet('route', 'review')
        ->set('route', 'sideways')->call('submit')->assertHasErrors('route')
        ->set('route', 'direct')->assertSee('Forward directly to the CEO')->assertSee('the Chief of Staff and the Assistant are not involved')
        ->call('submit')->assertHasNoErrors();

    expect($this->memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($this->memo->fresh()->isDirectToCeo())->toBeTrue();
});

it('keeps the review route as before: HOD, then the COS/ACOS office, then the CEO', function () {
    $this->lifecycle->submit($this->memo, $this->hod, SubmissionRoute::Review, ReviewOffice::ChiefOfStaff);
    expect($this->memo->fresh()->status)->toBe(ActivityStatus::UnderReview)
        ->and($this->memo->fresh()->submission_route)->toBe(SubmissionRoute::Review)
        ->and(kindsFor($this->cos))->toContain('submitted_for_review')
        ->and(kindsFor($this->ceo))->toBe([]);

    expect($this->lifecycle->forward($this->memo->fresh(), $this->cos, ReviewRecommendation::cases()[0], 'Reviewed.'))->toBe('the CEO');
    expect($this->memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and(app(MemoTracker::class)->query($this->cos, 'forwarded')->count())->toBe(1);

    // The CEO's return goes to the reviewers, as before.
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Returned, 'Clarify the budget.', $this->ceo);
    expect($this->memo->fresh()->status)->toBe(ActivityStatus::Returned);
});

it('keeps COS/ACOS out of a memo sent directly to the CEO, on the server and on the page', function () {
    $this->lifecycle->submitDirectToCeo($this->memo, $this->hod);
    $memo = $this->memo->fresh();
    $editor = app(ActivityEditor::class);
    $other = CostCategory::query()->where('code', 'other')->value('id');

    foreach ([$this->cos, $this->acos] as $reviewer) {
        expect(fn () => $this->lifecycle->addReviewNote($memo, 'A comment', null, $reviewer))->toThrow(AuthorizationException::class, 'directly to the CEO')
            ->and(fn () => $this->lifecycle->postpone($memo, 'Later', $reviewer))->toThrow(AuthorizationException::class)
            ->and(fn () => $editor->savePlannedCost($memo, ['cost_category_id' => $other, 'estimated_amount' => '1000'], $reviewer, amendmentReason: 'Add'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->lifecycle->forward($memo, $reviewer))->toThrow(Exception::class);
    }
    expect(fn () => $this->lifecycle->recordDecision($memo, DecisionType::Approved, null, 'REF/1', today(), UploadedFile::fake()->create('d.pdf', 10, 'application/pdf'), $this->cos))
        ->toThrow(AuthorizationException::class, 'directly to the CEO');

    // They can still read it, with no action and a clear note why.
    Livewire::actingAs($this->cos)->test(OfficeShow::class, ['activity' => $memo])
        ->assertOk()->assertSee('directly to the CEO')->assertDontSee('Record CEO decision')->assertDontSee('Add comment');

    // The CEO comments and decides.
    $this->lifecycle->addReviewNote($memo, 'Seen.', null, $this->ceo);
    $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo);
    expect($memo->fresh()->status)->toBe(ActivityStatus::Approved)->and($memo->fresh()->isReservedForCeo())->toBeFalse();
});

it('returns a direct memo to the Head of Department, who resubmits it straight to the CEO', function () {
    $this->lifecycle->submitDirectToCeo($this->memo, $this->hod);
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Returned, 'Add the venue cost.', $this->ceo);

    $memo = $this->memo->fresh();
    expect($memo->status)->toBe(ActivityStatus::ReturnedToHod)
        ->and($memo->status_reason)->toBe('Add the venue cost.')
        ->and($memo->decisions()->sole()->decision)->toBe(DecisionType::Returned)
        ->and(lastHistory($memo)->reason)->toBe('Returned by the CEO to the Head of Department for correction: Add the venue cost.')
        ->and(kindsFor($this->hod))->toContain('returned_for_correction')
        ->and($memo->nextAction())->toContain('resubmit it to the CEO')
        ->and(app(MemoTracker::class)->query($this->cos, 'with_hod')->count())->toBe(0)
        ->and(app(MemoTracker::class)->query($this->hod, 'returned')->count())->toBe(1);

    // The Head corrects it; the Chief of Staff still cannot.
    app(ActivityEditor::class)->update($memo, ['title' => 'Corrected title'], $this->hod);
    expect(fn () => app(ActivityEditor::class)->update($memo->fresh(), ['title' => 'x'], $this->cos))->toThrow(AuthorizationException::class);

    // Whatever route is asked for, a returned memo goes back the way it came.
    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo->fresh()])
        ->call('openSubmit')->assertSet('route', 'direct')->assertSee('directly to the CEO')
        ->set('route', 'review')->call('submit')->assertHasNoErrors();

    expect($memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($memo->fresh()->isDirectToCeo())->toBeTrue()
        ->and(lastHistory($memo)->reason)->toBe('Corrected and resubmitted directly to the CEO');
});

it('lets the CEO decline a direct memo', function () {
    $this->lifecycle->submitDirectToCeo($this->memo, $this->hod);
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Declined, 'Not this year.', $this->ceo);

    expect($this->memo->fresh()->status)->toBe(ActivityStatus::Declined);
});

it('leaves memos already in the review workflow, or approved, as they were', function () {
    // A memo submitted before the choice existed has no route and follows review.
    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::ChiefOfStaff);
    $this->memo->forceFill(['submission_route' => null])->saveQuietly();
    $memo = $this->memo->fresh();

    expect($memo->route())->toBe(SubmissionRoute::Review)->and($memo->isReservedForCeo())->toBeFalse();
    $this->lifecycle->addReviewNote($memo, 'Looks fine.', null, $this->cos);
    $this->lifecycle->forward($memo, $this->cos);
    $history = $memo->statusHistory()->pluck('to_status')->all();
    $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo);

    expect($memo->fresh()->status)->toBe(ActivityStatus::Approved)
        ->and($memo->statusHistory()->count())->toBe(count($history) + 1)
        ->and($memo->fresh()->submission_route)->toBeNull();
});

it('leaves out the review signatures on a memo sent directly to the CEO', function () {
    $this->lifecycle->submitDirectToCeo($this->memo, $this->hod);
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Approved, null, $this->ceo);

    $labels = collect(app(MemoDocument::class)->data($this->memo->fresh())['signatures'])->pluck('label')->all();
    expect($labels)->toBe(['Prepared by', 'Approved by']);

    // The review route keeps all four.
    $other = departmentMemo($this->hod, ['title' => 'Another memo']);
    $this->lifecycle->submitForReview($other, $this->hod, ReviewOffice::ChiefOfStaff);
    expect(collect(app(MemoDocument::class)->data($other->fresh())['signatures'])->pluck('label')->all())
        ->toBe(['Prepared by', 'Reviewed by', 'Validated by', 'Approved by']);
});

it('blocks a direct submission that is not ready for the CEO', function () {
    $memo = departmentMemo($this->hod, ['title' => 'No team']);
    $memo->participants()->delete();

    expect(fn () => $this->lifecycle->submitDirectToCeo($memo->fresh(), $this->hod))->toThrow(Exception::class)
        ->and($memo->fresh()->status)->toBe(ActivityStatus::Draft);
});

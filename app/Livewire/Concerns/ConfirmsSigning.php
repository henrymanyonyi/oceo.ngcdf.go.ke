<?php

namespace App\Livewire\Concerns;

use App\Enums\SigningAction;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Services\SignatureRegistry;
use App\Services\Signing;
use Illuminate\Support\Facades\Auth;

/**
 * The "confirm it is you" part of the Submit, Forward and Approve popups (App\Services\Signing): the
 * officer asks for a code, then types their password and the code with the action itself. Checked here
 * for a clear message, and again by ActivityLifecycle, which refuses the step without a confirmation,
 * whatever calls it. View: resources/views/components/memo/signing-confirm.blade.php.
 */
trait ConfirmsSigning
{
    public string $signPassword = '';

    public string $signCode = '';

    /** An officer with no signature yet draws it in the popup (a PNG data URI from the pad). */
    public string $signDrawing = '';

    /** @var list<string> where the code went, masked */
    public array $signCodeSentTo = [];

    /** An officer with no mobile number gives it here, so the code reaches their phone as well as their email. */
    public string $signPhone = '';

    /** Saves the officer's mobile number (password checked), then sends the code to it and their email. */
    public function saveSigningPhone(string $step, ?int $activityId = null): void
    {
        $this->validate(['signPhone' => ['required', 'string', 'max:30'], 'signPassword' => ['required', 'string']], [], ['signPhone' => 'mobile number', 'signPassword' => 'password']);

        try {
            app(Signing::class)->saveMobile(Auth::user(), $this->signPhone, $this->signPassword);
        } catch (ActivityWorkflowException $e) {
            $this->addError(str_contains($e->getMessage(), 'password') ? 'signPassword' : 'signPhone', $e->getMessage());

            return;
        }

        $this->signPhone = '';
        Auth::user()->refresh();
        $this->sendSigningCode($step, $activityId);
    }

    public function sendSigningCode(string $step, ?int $activityId = null): void
    {
        $activity = $this->signingActivity($activityId);
        $action = app(Signing::class)->actionFor($activity, Auth::user(), $step);
        if (! $action) {
            return;
        }

        try {
            $this->signCodeSentTo = app(Signing::class)->requestCode(Auth::user(), $activity, $action);
            $this->resetErrorBag('signCode');
        } catch (ActivityWorkflowException $e) {
            $this->addError('signCode', $e->getMessage());
        }
    }

    /**
     * Whether the step may go ahead: nothing to sign, step-up off, or the officer's signature exists (or is
     * drawn now) and their password and code are right. A signature drawn here is saved only after that.
     */
    protected function passesSigning(Activity $activity, ?SigningAction $action): bool
    {
        $signing = app(Signing::class);
        if (! $action || ! $signing->stepUpRequired()) {
            return true;
        }
        $registry = app(SignatureRegistry::class);
        $user = Auth::user();
        $drawsNow = $registry->activeFor($user) === null;

        $this->validate(
            ['signPassword' => ['required', 'string'], 'signCode' => ['required', 'digits:6']] + ($drawsNow ? ['signDrawing' => ['required', 'string']] : []),
            ['signDrawing.required' => 'You have no signature yet. Draw it on the pad first.'],
            ['signPassword' => 'password', 'signCode' => 'code'],
        );

        if ($drawsNow) {
            try {
                $registry->normalise($this->signDrawing);
            } catch (ActivityWorkflowException $e) {
                $this->addError('signDrawing', $e->getMessage());

                return false;
            }
        }

        try {
            $signing->confirm($user, $activity, $action, $this->signPassword, $this->signCode);
        } catch (ActivityWorkflowException $e) {
            $this->addError(str_contains($e->getMessage(), 'password') ? 'signPassword' : 'signCode', $e->getMessage());
            $this->signPassword = '';

            return false;
        }

        if ($drawsNow) {
            $registry->save($user, $this->signDrawing, 'Drawn by the officer while signing '.$activity->reference.', confirmed with their password and signing code');
        }

        $this->resetSigning();

        return true;
    }

    protected function resetSigning(): void
    {
        $this->reset(['signPassword', 'signCode', 'signCodeSentTo', 'signDrawing', 'signPhone']);
    }

    /** The memo the popup is about: the component's own, or one picked from a list (the decision queue). */
    private function signingActivity(?int $activityId): Activity
    {
        if ($activityId) {
            return Activity::query()->visibleTo(Auth::user())->findOrFail($activityId);
        }

        return $this->activity;
    }
}

<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Enums\SigningAction;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\SigningChallenge;
use App\Models\SigningEvent;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\MobileNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Digital signing of memos (November 2026). An uploaded image proves nothing by itself: a memo shows a
 * signature only where the officer, signed in, confirmed that very action on that very memo with their
 * password and a one-time code sent to their own email and mobile, and the memo has not changed since.
 *
 *  1. requestCode() sends a six-digit code (10 minutes, 5 tries, 3 codes per 10 minutes).
 *  2. confirm() checks the password and the code; the challenge is then good for one signing only.
 *  3. ActivityLifecycle calls sign() inside the same transaction as the step itself: it consumes the
 *     challenge and writes an immutable SigningEvent with the signer's account, role, designation,
 *     the hash of the memo's content (fingerprint()), a verification code, the IP and browser.
 *
 * Who may sign what is never chosen by the user: the action follows from the workflow step and the
 * signed-in account (actionFor()), and the lifecycle has already checked the permission. Step-up
 * (steps 1 and 2) is required unless ACTIVITIES_SIGNING_STEP_UP is false, which only the test suite
 * does; the signing record is written either way.
 */
class Signing
{
    public const CODE_MINUTES = 10;

    public const CODE_ATTEMPTS = 5;

    public function __construct(
        private OutboundAlerts $outbound,
        private SignatureRegistry $signatures,
        private AccessLogger $accessLog,
        private Alerts $alerts,
    ) {}

    public function stepUpRequired(): bool
    {
        return (bool) config('activities.signing.step_up', true);
    }

    /**
     * The signature a workflow step carries for this user, or null when the step signs nothing.
     *
     * @param  'submit'|'forward'|'decide'  $step
     */
    public function actionFor(Activity $activity, User $user, string $step): ?SigningAction
    {
        return match ($step) {
            'submit' => SigningAction::Prepared,
            'forward' => $activity->status === ActivityStatus::Draft
                ? SigningAction::Prepared
                : match (ReviewOffice::forUser($user)) {
                    ReviewOffice::AssistantChiefOfStaff => SigningAction::Reviewed,
                    ReviewOffice::ChiefOfStaff => SigningAction::Validated,
                    default => null,
                },
            'decide' => SigningAction::Approved,
            default => null,
        };
    }

    /**
     * Sends a code to the user's own email and mobile for this memo and action.
     *
     * @return list<string> where it went, masked
     */
    public function requestCode(User $user, Activity $activity, SigningAction $action): array
    {
        // The code goes to both the officer's email and their mobile (10 November 2026): a mobile number is needed.
        $email = filled($user->email) ? (string) $user->email : null;
        $mobile = $user->mobileNumber();
        if (! $mobile) {
            throw new ActivityWorkflowException('Add your mobile number first: the code is sent to your phone by SMS and to your email.');
        }

        $key = 'signing-code:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new ActivityWorkflowException('Three codes have been sent. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes before asking for another.');
        }
        RateLimiter::hit($key, 600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $activity, $action, $code, $email, $mobile) {
            // Only the newest code works.
            SigningChallenge::query()->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            $challenge = new SigningChallenge;
            $challenge->forceFill([
                'user_id' => $user->id,
                'activity_id' => $activity->id,
                'action' => $action,
                'code_hash' => '',
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'ip_address' => request()?->ip(),
            ])->save();
            $challenge->forceFill(['code_hash' => $this->codeHash($challenge, $code)])->save();

            $this->outbound->toContact($user->name, $email, $mobile, 'signing_code', [
                'subject' => 'Your signing code',
                'line' => "Your code to {$action->verb()} memo {$activity->reference} is {$code}. It works for ".self::CODE_MINUTES.' minutes.',
                'todo' => 'Type it in OCEO with your password. If you did not ask for it, tell the System Administrator at once.',
            ], null, $activity->id);
        });

        $this->accessLog->log('signing_code_sent', $activity, ['action' => $action->value]);

        return array_values(array_filter([
            $email ? preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email) : null,
            $mobile ? MobileNumber::mask($mobile) : null,
        ]));
    }

    /**
     * An officer with no mobile number on their account or the staff list gives it when they first sign.
     * Their password confirms it is them; the number is kept on their account (the staff list is HR's), and
     * they are told by email, so a number added by someone else is noticed.
     */
    public function saveMobile(User $user, string $number, string $password): string
    {
        $key = 'signing-mobile:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ActivityWorkflowException('Too many attempts. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes and try again.');
        }
        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, 900);
            $this->accessLog->log('signing_failed', $user, ['action' => 'add mobile number', 'reason' => 'The password is not right.']);

            throw new ActivityWorkflowException('The password is not right.');
        }
        $mobile = MobileNumber::normalise($number);
        if (! $mobile || ! MobileNumber::isValid($number)) {
            throw new ActivityWorkflowException('Enter a Kenyan mobile number, e.g. 0712 345 678.');
        }

        $user->forceFill(['phone' => $mobile])->save();
        $this->alerts->toUsers([$user->fresh()], ActivityAlert::mobileAdded(MobileNumber::mask($mobile)));

        return $mobile;
    }

    /** Checks the password and the code. The challenge is then good for one signing only. */
    public function confirm(User $user, Activity $activity, SigningAction $action, string $password, string $code): void
    {
        $passwordRight = Hash::check($password, (string) $user->getAuthPassword());

        $result = DB::transaction(function () use ($user, $activity, $action, $code, $passwordRight) {
            $challenge = $this->openChallenge($user, $activity, $action, lock: true);
            if (! $challenge || $challenge->expires_at->isPast()) {
                return 'The code has expired or was not asked for. Ask for a new one.';
            }
            if ($challenge->attempts >= self::CODE_ATTEMPTS) {
                return 'Too many wrong attempts. Ask for a new code.';
            }
            if (! $passwordRight || ! hash_equals($challenge->code_hash, $this->codeHash($challenge, trim($code)))) {
                // Counted (and committed) even though the attempt fails, so guessing runs out.
                $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

                return $passwordRight ? 'The code is not right.' : 'The password is not right.';
            }

            $challenge->forceFill(['verified_at' => now()])->save();

            return null;
        });

        if ($result !== null) {
            $this->accessLog->log('signing_failed', $activity, ['action' => $action->value, 'reason' => $result]);

            throw new ActivityWorkflowException($result);
        }
    }

    /**
     * Records the signing: called by ActivityLifecycle inside the step's transaction, after the step is
     * applied. Consumes the confirmed challenge; without one (and step-up required) the whole step fails.
     */
    public function sign(Activity $activity, User $user, SigningAction $action, ?string $designation = null): SigningEvent
    {
        $challenge = null;
        if ($this->stepUpRequired()) {
            $challenge = $this->openChallenge($user, $activity, $action, lock: true);
            if (! $challenge || ! $challenge->verified_at || $challenge->expires_at->isPast()) {
                $this->accessLog->log('signing_failed', $activity, ['action' => $action->value, 'reason' => 'Not confirmed with password and code']);

                throw new AuthorizationException('Confirm with your password and the code sent to you before you '.$action->verb().' this memo.');
            }
            $challenge->forceFill(['consumed_at' => now()])->save();

            // Every authorisation carries the officer's own drawn signature.
            if (! $this->signatures->activeFor($user)) {
                throw new AuthorizationException('Draw your signature before you '.$action->verb().' this memo.');
            }
        }

        $event = new SigningEvent;
        $event->forceFill([
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'action' => $action,
            'role' => (string) $user->roleName(),
            'signer_name' => $user->name,
            'designation' => $designation ?? $user->roleLabel(),
            'user_signature_id' => $this->signatures->activeFor($user)?->id,
            'content_hash' => $this->fingerprint($activity),
            'verification_code' => $this->newVerificationCode(),
            'auth_method' => $challenge ? 'password_and_code' : 'session',
            'signing_challenge_id' => $challenge?->id,
            'ip_address' => request()?->ip(),
            'user_agent' => mb_substr((string) request()?->userAgent(), 0, 500) ?: null,
            'signed_at' => now(),
        ])->save();

        return $event;
    }

    /**
     * The hash of what the memo says: its details, dates, team, places and planned costs, never its
     * status, workflow dates, actual spending or attendance. Any change to the content after signing
     * gives another hash, so the earlier signature no longer stands for the memo as it now reads.
     */
    public function fingerprint(Activity $activity): string
    {
        $a = Activity::query()->with(['participants', 'costs.participant', 'locations'])->findOrFail($activity->id);
        $date = fn ($d) => $d?->toDateString();

        $content = [
            'memo' => [
                'memo_number' => $a->memo_number, 'source_reference' => $a->source_reference, 'issued_on' => $date($a->issued_on),
                'title' => $a->title, 'purpose' => $a->purpose, 'justification' => $a->justification, 'risks' => $a->risks,
                'expected_outputs' => $a->expected_outputs, 'target_audience' => $a->target_audience, 'approval_request' => $a->approval_request,
                'memo_sections' => $a->memo_sections, 'notes' => $a->notes,
                'category' => $a->activity_category_id, 'category_other' => $a->category_other, 'type' => $a->activity_type_id,
                'department' => $a->organising_department_id, 'budget_line' => $a->budget_line_id, 'requested_budget' => (string) $a->requested_budget,
                'start' => $date($a->start_date), 'end' => $date($a->end_date), 'days' => $a->days, 'nights' => $a->nights,
                'responsible_hod' => $a->responsible_hod_id ?? $a->responsible_hod_name, 'hod_designation' => $a->hod_designation, 'hod_acting' => (bool) $a->hod_acting,
            ],
            'team' => $a->participants->map(fn ($p) => [
                $p->staff_id, $p->external_name, $p->external_organisation, $p->group_label, $p->headcount, $p->job_grade,
                $p->role?->value, $p->role_other, $date($p->start_date), $date($p->end_date), $p->days_planned, $p->rate_source, (string) $p->rate_amount, $p->rate_unit?->value,
            ])->sort()->values()->all(),
            'places' => $a->locations->map(fn ($l) => [$l->region_id, $l->county_id, $l->constituency_id, $l->venue])->sort()->values()->all(),
            'costs' => $a->costs->map(fn ($c) => [
                $c->cost_category_id, $c->participant?->staff_id ?? $c->participant?->external_name, $c->description,
                $c->calculation_method, (string) $c->quantity, (string) $c->unit_rate, (string) $c->estimated_amount,
            ])->sort()->values()->all(),
        ];

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** The latest signing of this memo for this action, and whether it still stands for the memo as it reads now. */
    public function latest(Activity $activity, SigningAction $action, ?string $currentHash = null): ?array
    {
        $event = SigningEvent::query()->with('signature')->where('activity_id', $activity->id)->where('action', $action->value)->latest('id')->first();
        if (! $event) {
            return null;
        }

        return ['event' => $event, 'valid' => hash_equals($event->content_hash, $currentHash ?? $this->fingerprint($activity))];
    }

    /**
     * What a verification code says (the verification page): who signed what and when, and whether the
     * memo still reads as it did. Null for an unknown code.
     *
     * @return array{event: SigningEvent, valid: bool, superseded_by: ?SigningEvent}|null
     */
    public function verify(string $code): ?array
    {
        $event = SigningEvent::query()->with(['activity', 'user'])->where('verification_code', strtoupper(trim($code)))->first();
        if (! $event || ! $event->activity) {
            return null;
        }

        $newer = SigningEvent::query()->where('activity_id', $event->activity_id)->where('action', $event->action->value)->where('id', '>', $event->id)->latest('id')->first();

        return ['event' => $event, 'valid' => hash_equals($event->content_hash, $this->fingerprint($event->activity)), 'superseded_by' => $newer];
    }

    private function openChallenge(User $user, Activity $activity, SigningAction $action, bool $lock = false): ?SigningChallenge
    {
        return SigningChallenge::query()
            ->where('user_id', $user->id)->where('activity_id', $activity->id)->where('action', $action->value)
            ->whereNull('consumed_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->latest('id')->first();
    }

    private function codeHash(SigningChallenge $challenge, string $code): string
    {
        return hash_hmac('sha256', $challenge->id.'|'.$challenge->user_id.'|'.$challenge->activity_id.'|'.$challenge->action->value.'|'.$code, (string) config('app.key'));
    }

    private function newVerificationCode(): string
    {
        do {
            $code = 'OCEO-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
        } while (SigningEvent::query()->where('verification_code', $code)->exists());

        return $code;
    }
}

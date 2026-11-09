<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Enums\SigningAction;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show as ActivityShow;
use App\Livewire\DecisionQueue;
use App\Livewire\Memos\Show as MemoShow;
use App\Livewire\SignatureForm;
use App\Livewire\Signatures;
use App\Livewire\VerifySignature;
use App\Mail\AlertMessage;
use App\Models\Activity;
use App\Models\Department;
use App\Models\OutboundMessage;
use App\Models\SigningEvent;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\ActivityLifecycle;
use App\Services\MemoDocument;
use App\Services\SignatureRegistry;
use App\Services\Signing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    config(['activities.signing.step_up' => true]);
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->signing = app(Signing::class);
    $this->registry = app(SignatureRegistry::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->acos = assistant();
    $this->cos = chiefOfStaff();
    $this->ceo = ceo();
    $this->admin = User::factory()->admin()->create();
});

/**
 * A signature as the pad sends it: a PNG data URI of the pad's size with a stroke across it (or, with
 * $kind, a blank pad, a filled block, or the wrong size).
 */
function drawnSignature(string $kind = 'signature'): string
{
    [$w, $h] = $kind === 'small' ? [300, 100] : [SignatureRegistry::WIDTH, SignatureRegistry::HEIGHT];
    $image = imagecreatetruecolor($w, $h);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
    $ink = imagecolorallocate($image, 11, 31, 77);
    imagesetthickness($image, 5);
    if ($kind === 'block') {
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, $ink);
    } elseif ($kind !== 'blank') {
        for ($x = 100; $x < 1000; $x += 40) {
            imageline($image, $x, 250 + (int) (60 * sin($x / 50)), $x + 40, 250 + (int) (60 * sin(($x + 40) / 50)), $ink);
        }
        imageline($image, 300, 120, 340, 320, $ink);
    }
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** The officer draws and saves their signature (as in Profile › Signature). */
function activeSignature(User $user): UserSignature
{
    return app(SignatureRegistry::class)->saveWithPassword($user, drawnSignature(), 'password');
}

/** Asks for a signing code and returns it, read from the email the officer receives. */
function signingCode(User $user, Activity $activity, SigningAction $action): string
{
    // The code goes by SMS and email, so the officer needs a mobile number.
    if (! $user->mobileNumber()) {
        $user->forceFill(['phone' => '+2547'.random_int(10000000, 99999999)])->save();
    }
    app(Signing::class)->requestCode($user, $activity, $action);
    $code = null;
    Mail::assertSent(AlertMessage::class, function (AlertMessage $m) use ($user, &$code) {
        if ($m->hasTo($user->email) && preg_match('/is (\d{6})\./', $m->text, $match)) {
            $code = $match[1];
        }

        return true;
    });

    return $code;
}

/** Confirms with password and code, then runs the step. */
function signed(User $user, Activity $activity, SigningAction $action, Closure $step): mixed
{
    if (! app(SignatureRegistry::class)->activeFor($user)) {
        activeSignature($user);
    }

    app(Signing::class)->confirm($user, $activity, $action, 'password', signingCode($user, $activity, $action));

    return $step();
}

describe('signature registration', function () {
    it('keeps a drawn signature as the officer\'s own, in use at once, replacing the previous one, and tells them', function () {
        $first = activeSignature($this->ceo);
        $second = activeSignature($this->ceo);

        expect($this->registry->activeFor($this->ceo)->id)->toBe($second->id)
            ->and($first->fresh()->status)->toBe(UserSignature::SUPERSEDED)
            ->and($second)->mime_type->toBe('image/png')->decided_by->toBe($this->ceo->id)
            ->and($this->ceo->notifications()->where('data->kind', 'signature_saved')->count())->toBeGreaterThan(0);
    });

    it('needs the officer\'s password to save a signature', function () {
        expect(fn () => $this->registry->saveWithPassword($this->ceo, drawnSignature(), 'wrong'))->toThrow(ActivityWorkflowException::class, 'password')
            ->and($this->registry->activeFor($this->ceo))->toBeNull();
    });

    it('accepts only a signature drawn on the pad: not blank, not a filled block, not another size, not another file', function (string $kind, string $message) {
        $dataUri = match ($kind) {
            'jpeg' => 'data:image/jpeg;base64,'.base64_encode('not a png'),
            'garbage' => 'data:image/png;base64,'.base64_encode('not an image at all'),
            default => drawnSignature($kind),
        };

        expect(fn () => $this->registry->saveWithPassword($this->ceo, $dataUri, 'password'))->toThrow(ActivityWorkflowException::class, $message);
    })->with([
        'blank pad' => ['blank', 'empty'],
        'filled block' => ['block', 'does not look like a signature'],
        'wrong size' => ['small', 'signature pad'],
        'a JPEG' => ['jpeg', 'Draw your signature'],
        'not an image' => ['garbage', 'signature pad'],
    ]);

    it('refuses a signature for an account that signs nothing', function () {
        expect(fn () => $this->registry->saveWithPassword($this->admin, drawnSignature(), 'password'))->toThrow(AuthorizationException::class);
    });

    it('stores the drawing encrypted and shows it only to its owner, the System Administrator and the CEO', function () {
        $signature = activeSignature($this->hod);

        expect(Storage::disk('local')->get($signature->path))->not->toStartWith("\x89PNG")
            ->and($this->registry->contents($signature))->toStartWith("\x89PNG");

        $this->actingAs($this->hod)->get(route('signatures.image', $signature))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($this->admin)->get(route('signatures.image', $signature))->assertOk();
        $this->actingAs($this->ceo)->get(route('signatures.image', $signature))->assertOk();
        // Security 4: another officer cannot fetch the Head's signature.
        $this->actingAs($this->cos)->get(route('signatures.image', $signature))->assertForbidden();
        $this->actingAs(headOfDepartment(Department::query()->where('name', 'Human Resources')->firstOrFail()))->get(route('signatures.image', $signature))->assertForbidden();
    });

    it('never uses an image whose file was altered', function () {
        $signature = activeSignature($this->ceo);
        Storage::disk('local')->put($signature->path, Crypt::encryptString('forged'));

        expect(fn () => $this->registry->contents($signature))->toThrow(ActivityWorkflowException::class);
    });
});

describe('signing a memo', function () {
    it('signs each step only after password and code, and puts each officer\'s signature in their block', function () {
        foreach ([$this->hod, $this->acos, $this->ceo] as $officer) {
            activeSignature($officer);
        }
        $memo = departmentMemo($this->hod);

        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff));
        signed($this->acos, $memo, SigningAction::Reviewed, fn () => $this->lifecycle->forward($memo->fresh(), $this->acos));
        signed($this->ceo, $memo, SigningAction::Approved, fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo));

        expect(SigningEvent::query()->where('activity_id', $memo->id)->orderBy('id')->pluck('action')->map->value->all())->toBe(['prepared', 'reviewed', 'approved'])
            ->and(SigningEvent::query()->where('action', 'approved')->sole())
            ->user_id->toBe($this->ceo->id)->auth_method->toBe('password_and_code')->user_signature_id->not->toBeNull();

        $blocks = collect(app(MemoDocument::class)->data($memo->fresh())['signatures'])->keyBy('label');
        expect($blocks['Prepared by']['signed'])->valid->toBeTrue()->image->toStartWith('data:image/png;base64,')
            ->and($blocks['Reviewed by']['name'])->toBe($this->acos->name)
            ->and($blocks['Approved by']['name'])->toBe($this->ceo->name)
            ->and($blocks['Approved by']['signed']['valid'])->toBeTrue()
            ->and($blocks['Validated by']['signed'])->toBeNull();

        // The generated PDF carries the verification codes.
        $this->actingAs($this->ceo)->get(route('memos.document', $memo))->assertOk();
    });

    it('refuses to authorise a memo for an officer with no signature, even with the right password and code', function () {
        $memo = departmentMemo($this->hod);
        $this->signing->confirm($this->hod, $memo, SigningAction::Prepared, 'password', signingCode($this->hod, $memo, SigningAction::Prepared));

        expect(fn () => $this->lifecycle->submitForReview($memo, $this->hod))->toThrow(AuthorizationException::class, 'Draw your signature')
            ->and($memo->fresh()->status)->toBe(ActivityStatus::Draft);
    });

    it('lets an officer with no signature draw it in the signing popup, saved as theirs and put on the memo', function () {
        $memo = departmentMemo($this->hod);
        $code = signingCode($this->hod, $memo, SigningAction::Prepared);

        Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])
            ->call('openSubmit')
            ->set('signPassword', 'password')->set('signCode', $code)
            ->call('submit')->assertHasErrors(['signDrawing'])
            ->set('signDrawing', drawnSignature('blank'))->set('signPassword', 'password')
            ->call('submit')->assertHasErrors(['signDrawing'])
            ->set('signDrawing', drawnSignature())->set('signPassword', 'password')
            ->call('submit')->assertHasNoErrors();

        $signature = $this->registry->activeFor($this->hod);
        expect($signature)->not->toBeNull()
            ->and($memo->fresh()->status)->toBe(ActivityStatus::UnderReview)
            ->and(SigningEvent::query()->where('action', 'prepared')->sole()->user_signature_id)->toBe($signature->id);
    });

    it('sends the code by both SMS and email, and asks an officer with no mobile number for it first', function () {
        $memo = departmentMemo($this->hod);

        expect(fn () => $this->signing->requestCode($this->hod, $memo, SigningAction::Prepared))->toThrow(ActivityWorkflowException::class, 'Add your mobile number');

        Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])
            ->call('openSubmit')
            ->assertSee('Your mobile number is not on your account')
            ->set('signPhone', '0712 345 678')->set('signPassword', 'wrong')
            ->call('saveSigningPhone', 'submit')->assertHasErrors(['signPassword'])
            ->set('signPhone', '12345')->set('signPassword', 'password')
            ->call('saveSigningPhone', 'submit')->assertHasErrors(['signPhone'])
            ->set('signPhone', '0712 345 678')->set('signPassword', 'password')
            ->call('saveSigningPhone', 'submit')->assertHasNoErrors()
            ->assertSet('signCodeSentTo', fn (array $to) => count($to) === 2);

        expect($this->hod->fresh()->phone)->toBe('+254712345678')
            ->and(OutboundMessage::query()->where('kind', 'signing_code')->pluck('channel')->sort()->values()->all())->toBe(['mail', 'sms'])
            ->and($this->hod->notifications()->where('data->kind', 'mobile_added')->exists())->toBeTrue();
    });

    it('scrubs the signing code from the message log once sent', function () {
        $memo = departmentMemo($this->hod);
        signingCode($this->hod, $memo, SigningAction::Prepared);

        expect(OutboundMessage::query()->where('kind', 'signing_code')->pluck('body')->implode(' '))->toContain('******')->not->toMatch('/\b\d{6}\b/');
    });

    it('shows no signature on a block nobody signed, even when the officer has a registered signature', function () {
        activeSignature($this->ceo);
        $memo = departmentMemo($this->hod);

        $blocks = collect(app(MemoDocument::class)->data($memo)['signatures'])->keyBy('label');

        expect($blocks['Approved by'])->signed->toBeNull()->name->toBeNull();
    });
});

describe('security', function () {
    it('1: never shows the CEO as approving when the decision was recorded on the CEO\'s behalf', function () {
        activeSignature($this->ceo);
        $memo = capturedActivity($this->cos);

        $this->lifecycle->recordDecision($memo, DecisionType::Approved, null, 'OCEO/12/2026', today(), UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'), $this->cos);

        $approved = collect(app(MemoDocument::class)->data($memo->fresh())['signatures'])->firstWhere('label', 'Approved by');
        expect($approved)->name->toBeNull()->signed->toBeNull()->date->toBeNull()
            ->and($approved['note'])->toContain('recorded by '.$this->cos->name)
            ->and(SigningEvent::query()->where('action', 'approved')->exists())->toBeFalse();
    });

    it('2 and 7: lets nobody but the CEO approve, whatever they send', function () {
        $memo = departmentMemo($this->hod);
        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitDirectToCeo($memo, $this->hod));
        // Hand-made challenge for the CEO's action, confirmed by the CEO: it still cannot be used by anyone else.
        $this->signing->confirm($this->ceo, $memo, SigningAction::Approved, 'password', signingCode($this->ceo, $memo, SigningAction::Approved));

        foreach ([$this->cos, $this->acos, $this->hod] as $other) {
            expect(fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $other))->toThrow(AuthorizationException::class);
        }
        Livewire::actingAs($this->cos)->test(ActivityShow::class, ['activity' => $memo->fresh()])
            ->set('decision', 'approved')->call('decide');
        Livewire::actingAs($this->cos)->test(DecisionQueue::class)->call('open', $memo->id, 'approved')->call('decide');

        expect($memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
            ->and(SigningEvent::query()->where('action', 'approved')->exists())->toBeFalse();
    });

    it('3: refuses a step sent straight to the server without the officer\'s confirmation', function () {
        $memo = departmentMemo($this->hod);

        expect(fn () => $this->lifecycle->submitForReview($memo, $this->hod))->toThrow(AuthorizationException::class);
        Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])->call('openSubmit')->call('submit')->assertHasErrors(['signPassword', 'signCode']);

        expect($memo->fresh())->status->toBe(ActivityStatus::Draft)->memo_number->toBeNull()
            ->and(SigningEvent::query()->count())->toBe(0);
    });

    it('5: invalidates the approval for a changed memo, keeping the record', function () {
        activeSignature($this->ceo);
        $memo = departmentMemo($this->hod);
        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitDirectToCeo($memo, $this->hod));
        signed($this->ceo, $memo, SigningAction::Approved, fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo));
        $code = SigningEvent::query()->where('action', 'approved')->value('verification_code');

        // Someone changes the approved memo (here straight in the database, the worst case).
        Activity::query()->whereKey($memo->id)->update(['purpose' => 'A different purpose the CEO never saw']);

        $approved = collect(app(MemoDocument::class)->data($memo->fresh())['signatures'])->firstWhere('label', 'Approved by');
        expect($approved['signed'])->valid->toBeFalse()->image->toBeNull()
            ->and($this->signing->verify($code)['valid'])->toBeFalse()
            ->and(fn () => SigningEvent::query()->where('verification_code', $code)->first()->forceFill(['content_hash' => 'x'])->save())->toThrow(LogicException::class);
    });

    it('6: never accepts a code twice, for another memo or action, after expiry, or after five wrong tries', function () {
        $memo = departmentMemo($this->hod);
        $other = departmentMemo($this->hod);
        activeSignature($this->hod);

        $code = signingCode($this->hod, $memo, SigningAction::Prepared);
        expect(fn () => $this->signing->confirm($this->hod, $other, SigningAction::Prepared, 'password', $code))->toThrow(ActivityWorkflowException::class);

        $this->signing->confirm($this->hod, $memo, SigningAction::Prepared, 'password', $code);
        $this->lifecycle->submitForReview($memo, $this->hod);

        // Used once; the same code and the spent confirmation open nothing more.
        expect(fn () => $this->signing->confirm($this->hod, $memo, SigningAction::Prepared, 'password', $code))->toThrow(ActivityWorkflowException::class);

        $code = signingCode($this->hod, $other, SigningAction::Prepared);
        expect(fn () => $this->signing->confirm($this->hod, $other, SigningAction::Prepared, 'wrong-password', $code))->toThrow(ActivityWorkflowException::class, 'password');
        $this->travel(11)->minutes();
        expect(fn () => $this->signing->confirm($this->hod, $other, SigningAction::Prepared, 'password', $code))->toThrow(ActivityWorkflowException::class, 'expired');
    });

    it('6: locks a code after five wrong attempts', function () {
        $memo = departmentMemo($this->hod);
        $code = signingCode($this->hod, $memo, SigningAction::Prepared);
        foreach (range(1, 5) as $i) {
            try {
                $this->signing->confirm($this->hod, $memo, SigningAction::Prepared, 'password', '000000');
            } catch (ActivityWorkflowException) {
            }
        }

        expect(fn () => $this->signing->confirm($this->hod, $memo, SigningAction::Prepared, 'password', $code))->toThrow(ActivityWorkflowException::class, 'Too many');
    });

    it('7: keeps the Assistant from forwarding a memo that is with the Chief of Staff', function () {
        $memo = departmentMemo($this->hod);
        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::ChiefOfStaff));

        expect(fn () => signed($this->acos, $memo, SigningAction::Reviewed, fn () => $this->lifecycle->forward($memo->fresh(), $this->acos)))->toThrow(ActivityWorkflowException::class);
        expect(SigningEvent::query()->where('action', 'reviewed')->exists())->toBeFalse();
    });
});

describe('verification page', function () {
    it('confirms a genuine code to someone who may read the memo, and says nothing about it to anyone else', function () {
        $memo = departmentMemo($this->hod);
        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitDirectToCeo($memo, $this->hod));
        $code = SigningEvent::query()->value('verification_code');

        Livewire::actingAs($this->ceo)->test(VerifySignature::class, ['code' => $code])->assertSee('Valid.')->assertSee($this->hod->name);
        Livewire::actingAs(headOfDepartment(Department::query()->where('name', 'Human Resources')->firstOrFail()))
            ->test(VerifySignature::class, ['code' => $code])->assertSee('No memo you can open has this code')->assertDontSee($this->hod->name);
        Livewire::actingAs($this->ceo)->test(VerifySignature::class, ['code' => 'OCEO-FAKE-CODE'])->assertSee('No memo you can open has this code');
    });
});

describe('pages', function () {
    it('lets an officer delete their signature: memos already signed keep it, and the next signing asks for a new one', function () {
        $memo = departmentMemo($this->hod);
        signed($this->hod, $memo, SigningAction::Prepared, fn () => $this->lifecycle->submitDirectToCeo($memo, $this->hod));
        $signature = $this->registry->activeFor($this->hod);

        Livewire::actingAs($this->hod)->test(SignatureForm::class)
            ->assertSee('Delete signature')
            ->call('revoke')->assertHasNoErrors()
            ->assertSee('Draw my signature now');

        expect($signature->fresh()->status)->toBe(UserSignature::REVOKED)
            ->and($this->registry->activeFor($this->hod))->toBeNull()
            ->and(collect(app(MemoDocument::class)->data($memo->fresh())['signatures'])->firstWhere('label', 'Prepared by')['signed']['image'])->toStartWith('data:image/png;base64,');

        $next = departmentMemo($this->hod);
        $this->signing->confirm($this->hod, $next, SigningAction::Prepared, 'password', signingCode($this->hod, $next, SigningAction::Prepared));
        expect(fn () => $this->lifecycle->submitForReview($next, $this->hod))->toThrow(AuthorizationException::class, 'Draw your signature');
    });

    it('lets an officer draw and save their signature in the Profile, and shows the System Administrator who still has to', function () {
        Livewire::actingAs($this->hod)->test(SignatureForm::class)
            ->set('drawing', drawnSignature())->set('password', 'password')
            ->call('save')->assertHasNoErrors();

        expect($this->registry->activeFor($this->hod))->not->toBeNull();

        $this->actingAs($this->hod)->get(route('profile.show'))->assertOk()->assertSee('Draw a new signature');
        $this->actingAs($this->admin)->get(route('signatures.index'))->assertOk()->assertSee($this->hod->name)->assertSee($this->acos->name);
        $this->actingAs($this->cos)->get(route('signatures.index'))->assertForbidden();
        $this->actingAs($this->hod)->get(route('signatures.index'))->assertForbidden();

        $signature = $this->registry->activeFor($this->hod);
        Livewire::actingAs($this->admin)->test(Signatures::class)
            ->call('openRevoke', $signature->id)->set('note', 'Device lost')->call('revoke');
        expect($signature->fresh()->status)->toBe(UserSignature::REVOKED);
    });
});

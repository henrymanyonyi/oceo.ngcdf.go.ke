<?php

namespace App\Services;

use App\Exceptions\ActivityWorkflowException;
use App\Models\User;
use App\Models\UserSignature;
use App\Notifications\ActivityAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Officers' signatures (November 2026; drawn, not uploaded, since 10 November 2026). An officer draws
 * their signature on the signature pad (components/memo/signature-pad) in their Profile, or in the
 * signing popup the first time they sign. Nothing is uploaded: what reaches the server is a PNG of
 * the pad, which is accepted only if it is a real PNG of the pad's size with ink on it (not blank, not
 * a filled block or a pasted photograph), then drawn again through GD so nothing but the pixels is
 * kept, encrypted at rest and checked against its SHA-256 whenever it is read.
 *
 * Saving needs the officer's own confirmation: their password in the Profile, or the password and the
 * one-time code of the signing they are doing. The new signature is in use at once, the previous one is
 * kept as Replaced (still shown on what it signed), and the officer is told by email and SMS so a change
 * they did not make is noticed. The System Administrator and the CEO (`signatures.verify`) see the
 * signatures in use and can deactivate one. A signature is put on a memo only through a SigningEvent.
 */
class SignatureRegistry
{
    /** The pad's canvas, in pixels: what a drawing must measure. */
    public const WIDTH = 1200;

    public const HEIGHT = 400;

    public const MAX_KB = 400;

    public function __construct(private Alerts $alerts) {}

    /** Only officers whose work signs memos keep a signature. */
    public function maySign(User $user): bool
    {
        return $user->is_active && $user->canAny(['memos.originate', 'memos.review', 'activities.decide', 'activities.submit']);
    }

    /** Profile › Signature: the officer's password confirms it is them drawing. */
    public function saveWithPassword(User $user, string $dataUri, string $password): UserSignature
    {
        $key = 'signature-save:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ActivityWorkflowException('Too many attempts. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes and try again.');
        }
        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, 900);
            app(AccessLogger::class)->log('signing_failed', $user, ['action' => 'save signature', 'reason' => 'The password is not right.']);

            throw new ActivityWorkflowException('The password is not right.');
        }

        return $this->save($user, $dataUri, 'Drawn by the officer and confirmed with their password');
    }

    /**
     * Keeps the drawing as the officer's signature. Only after the officer has confirmed it is them
     * (saveWithPassword(), or ConfirmsSigning after Signing::confirm()).
     */
    public function save(User $user, string $dataUri, string $how): UserSignature
    {
        if (! $this->maySign($user)) {
            throw new AuthorizationException('Your account does not sign memos.');
        }
        $png = $this->normalise($dataUri);
        $path = 'signatures/'.Str::random(40);
        Storage::disk(DocumentStore::DISK)->put($path, Crypt::encryptString($png));

        $signature = DB::transaction(function () use ($user, $path, $png, $how) {
            UserSignature::query()->where('user_id', $user->id)->whereIn('status', [UserSignature::ACTIVE, UserSignature::PENDING])->get()
                ->each(fn (UserSignature $s) => $s->forceFill(['status' => UserSignature::SUPERSEDED])->save());

            return UserSignature::query()->forceCreate([
                'user_id' => $user->id,
                'status' => UserSignature::ACTIVE,
                'path' => $path,
                'mime_type' => 'image/png',
                'sha256' => hash('sha256', $png),
                'submitted_at' => now(),
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $how,
            ]);
        });

        $this->alerts->toUsers([$user->fresh()], ActivityAlert::signatureSaved());

        return $signature;
    }

    /**
     * Checks a drawing from the pad and returns it as a clean PNG, or says what is wrong. The check is
     * on the pixels, never on anything the browser says about them.
     */
    public function normalise(string $dataUri): string
    {
        $prefix = 'data:image/png;base64,';
        if (! str_starts_with($dataUri, $prefix)) {
            throw new ActivityWorkflowException('Draw your signature on the pad.');
        }
        $bytes = base64_decode(substr($dataUri, strlen($prefix)), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_KB * 1024) {
            throw new ActivityWorkflowException('The drawing could not be read. Clear the pad and draw your signature again.');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[2] !== IMAGETYPE_PNG || $info[0] !== self::WIDTH || $info[1] !== self::HEIGHT) {
            throw new ActivityWorkflowException('Use the signature pad to draw your signature.');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw new ActivityWorkflowException('The drawing could not be read. Clear the pad and draw your signature again.');
        }

        // Ink: dark, visible pixels. A signature covers a little of the pad and spans part of its width.
        [$ink, $sampled, $left, $right, $top, $bottom] = [0, 0, self::WIDTH, 0, self::HEIGHT, 0];
        for ($y = 0; $y < self::HEIGHT; $y += 2) {
            for ($x = 0; $x < self::WIDTH; $x += 2) {
                $sampled++;
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['alpha'] < 80 && ($c['red'] + $c['green'] + $c['blue']) < 450) {
                    $ink++;
                    [$left, $right, $top, $bottom] = [min($left, $x), max($right, $x), min($top, $y), max($bottom, $y)];
                }
            }
        }
        $share = $ink / $sampled;
        if ($ink < 150 || ($right - $left) < self::WIDTH * 0.12 || ($bottom - $top) < self::HEIGHT * 0.08) {
            imagedestroy($image);

            throw new ActivityWorkflowException('The pad looks empty or too small. Draw your full signature across the pad.');
        }
        if ($share > 0.25) {
            imagedestroy($image);

            throw new ActivityWorkflowException('That does not look like a signature drawn on the pad. Clear it and draw your signature.');
        }

        // Drawn again on a clean transparent canvas: only the pixels are kept.
        $clean = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($clean, false);
        imagesavealpha($clean, true);
        imagefill($clean, 0, 0, imagecolorallocatealpha($clean, 255, 255, 255, 127));
        imagealphablending($clean, true);
        imagecopy($clean, $image, 0, 0, 0, 0, self::WIDTH, self::HEIGHT);
        ob_start();
        imagepng($clean, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($clean);

        return $png;
    }

    /**
     * Stops a signature being used from now on: by its owner, or by the System Administrator or the CEO.
     * What it already signed keeps its signing record.
     */
    public function revoke(UserSignature $signature, User $user, string $reason): bool
    {
        if ((int) $signature->user_id !== $user->id && ! $user->can('signatures.verify')) {
            throw new AuthorizationException('You can deactivate only your own signature.');
        }
        if (trim($reason) === '') {
            throw new ActivityWorkflowException('Give the reason for deactivating the signature.');
        }
        if ($signature->status !== UserSignature::ACTIVE) {
            throw new ActivityWorkflowException('This signature is not in use.');
        }

        $signature->forceFill(['status' => UserSignature::REVOKED, 'revoked_by' => $user->id, 'revoked_at' => now(), 'revoked_reason' => trim($reason)])->save();

        return true;
    }

    public function activeFor(User $user): ?UserSignature
    {
        return UserSignature::query()->where('user_id', $user->id)->active()->latest('id')->first();
    }

    /** Whether this user may see this image outside a memo: its owner, or the System Administrator or the CEO. */
    public function mayView(User $user, UserSignature $signature): bool
    {
        return (int) $signature->user_id === $user->id || $user->can('signatures.verify');
    }

    /** The decrypted image, checked against the hash taken when it was saved. */
    public function contents(UserSignature $signature): string
    {
        $contents = Crypt::decryptString(Storage::disk(DocumentStore::DISK)->get($signature->path));
        if (! hash_equals($signature->sha256, hash('sha256', $contents))) {
            throw new ActivityWorkflowException('The signature file has been altered and cannot be used.');
        }

        return $contents;
    }

    public function dataUri(UserSignature $signature): string
    {
        return 'data:'.$signature->mime_type.';base64,'.base64_encode($this->contents($signature));
    }
}

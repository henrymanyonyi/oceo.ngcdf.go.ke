<?php

namespace App\Models;

use App\Enums\SigningAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code sent to an officer before they sign one memo for one action (App\Services\Signing).
 * Confirmed with their password and the code, then consumed by the signing itself; never reused.
 */
class SigningChallenge extends Model
{
    /** Written only by App\Services\Signing. */
    protected $guarded = ['*'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'action' => SigningAction::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

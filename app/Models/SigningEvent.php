<?php

namespace App\Models;

use App\Enums\SigningAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One officer signing one memo for one action, bound to the hash of the memo's content at that
 * moment (App\Services\Signing). Immutable: it is never updated or deleted, so the history of who
 * authorised what cannot be rewritten. A memo changed afterwards keeps the record, but the
 * signature no longer stands for the changed memo.
 */
class SigningEvent extends Model
{
    public const UPDATED_AT = null;

    /** Written only by App\Services\Signing. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'action' => SigningAction::class,
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A signing record cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A signing record cannot be deleted.'));
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserSignature, $this> */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(UserSignature::class, 'user_signature_id');
    }
}

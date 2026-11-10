<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An officer's signature image, encrypted at rest (App\Services\SignatureRegistry). Used on a memo
 * only through a SigningEvent; never served by URL except to its owner and to a verifier.
 */
class UserSignature extends Model
{
    use Auditable;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    /** Replaced by a newer signature the officer registered; still shown on what it signed. */
    public const SUPERSEDED = 'superseded';

    /** Written only by App\Services\SignatureRegistry. */
    protected $guarded = ['*'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    /** @param Builder<static> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Awaiting verification',
            self::ACTIVE => 'Active',
            self::REJECTED => 'Not accepted',
            self::REVOKED => 'Deactivated',
            self::SUPERSEDED => 'Replaced',
            default => ucfirst((string) $this->status),
        };
    }
}

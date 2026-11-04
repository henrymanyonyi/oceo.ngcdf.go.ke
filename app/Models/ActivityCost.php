<?php

namespace App\Models;

use App\Enums\CostMethod;
use App\Enums\TravelMode;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost line. Carries both the estimate and the actual so planned vs actual
 * is a column comparison. A null participant means a shared, activity-level cost.
 */
class ActivityCost extends Model
{
    use Auditable;

    protected $fillable = [
        'activity_id',
        'cost_category_id',
        'activity_participant_id',
        'description',
        'calculation_method',
        'quantity',
        'unit_rate',
        'estimated_amount',
        'actual_amount',
        'is_computed',
        'dsa_rate_id',
        'computed_amount',
        'override_reason',
        'travel_mode',
        'finance_reference',
        'actual_recorded_by',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_rate' => 'decimal:2',
            'estimated_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'computed_amount' => 'decimal:2',
            'is_computed' => 'boolean',
            'travel_mode' => TravelMode::class,
            'calculation_method' => CostMethod::class,
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<CostCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class, 'cost_category_id');
    }

    /** @return BelongsTo<DsaRate, $this> */
    public function dsaRate(): BelongsTo
    {
        return $this->belongsTo(DsaRate::class);
    }

    public function isOverridden(): bool
    {
        return $this->is_computed && $this->computed_amount !== null && bccomp((string) $this->computed_amount, (string) $this->estimated_amount, 2) !== 0;
    }

    /** @return BelongsTo<ActivityParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ActivityParticipant::class, 'activity_participant_id');
    }

    /** How the amount is worked out; a line entered before methods existed is a fixed amount. */
    public function method(): CostMethod
    {
        return $this->calculation_method ?? CostMethod::Fixed;
    }

    /** Number of people on a per-person line. */
    public function people(): ?int
    {
        return $this->method() === CostMethod::PerPerson && $this->quantity !== null ? (int) $this->quantity : null;
    }

    /** "KES 4,000.00 × 10 people", or null for a fixed amount and for computed lines (they carry their own description). */
    public function calculationLabel(): ?string
    {
        if ($this->is_computed || $this->method() !== CostMethod::PerPerson || $this->unit_rate === null) {
            return null;
        }

        $people = (int) $this->people();

        return 'KES '.Money::format(Money::toCents($this->unit_rate)).' × '.$people.' '.($people === 1 ? 'person' : 'people');
    }

    public function isShared(): bool
    {
        return $this->activity_participant_id === null;
    }

    /** Actual where entered, estimate otherwise. */
    public function bestKnownAmount(): float
    {
        return (float) ($this->actual_amount ?? $this->estimated_amount);
    }
}

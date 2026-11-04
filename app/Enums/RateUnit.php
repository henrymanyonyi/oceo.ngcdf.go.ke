<?php

namespace App\Enums;

use App\Support\DsaPeriod;

/**
 * What an external participant's payment rate is paid per (4 November 2026).
 * Per night follows the one rule for payable nights (App\Support\DsaPeriod:
 * the departure night too for two days or more, none for a same-day
 * activity), as staff DSA does. Per day pays each day they take part. Once is
 * a single payment for the activity.
 */
enum RateUnit: string
{
    case Night = 'night';
    case Day = 'day';
    case Once = 'once';

    public function label(): string
    {
        return match ($this) {
            self::Night => 'Per night',
            self::Day => 'Per day',
            self::Once => 'Once (for the activity)',
        };
    }

    /** "nights", "days" or "once", after a number. */
    public function plural(int $units): string
    {
        return match ($this) {
            self::Night => $units === 1 ? 'night' : 'nights',
            self::Day => $units === 1 ? 'day' : 'days',
            self::Once => 'once',
        };
    }

    /** How many times the rate is paid for this person's days. */
    public function units(DsaPeriod $period): int
    {
        return match ($this) {
            self::Night => $period->nights(),
            self::Day => $period->days,
            self::Once => 1,
        };
    }

    /** The unit of a DSA scale rate: per night, or per day when Settings › Thresholds DSA basis is days. */
    public static function forDsaBasis(?string $basis): self
    {
        return $basis === DsaPeriod::BASIS_DAYS ? self::Day : self::Night;
    }
}

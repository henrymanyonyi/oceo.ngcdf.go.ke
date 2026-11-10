<?php

namespace App\Enums;

/**
 * How a planned cost line is worked out (4 November 2026). Per person: the
 * cost for one person × the number of people (lunch at KES 4,000 for 10 people
 * is KES 40,000). Per person per day (10 November 2026): × the number of days
 * too (meals at KES 1,500 for 10 people over 3 days is KES 45,000). Fixed: one
 * amount for the whole activity, whatever the number of people. A line with no
 * method (entered before this) is fixed.
 */
enum CostMethod: string
{
    case PerPerson = 'per_person';
    case PerPersonPerDay = 'per_person_per_day';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::PerPerson => 'Per person',
            self::PerPersonPerDay => 'Per person per day',
            self::Fixed => 'Fixed cost (per activity)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PerPerson => 'Per person',
            self::PerPersonPerDay => 'Per person per day',
            self::Fixed => 'Fixed',
        };
    }

    /** How it is worked out, in a line. */
    public function hint(): string
    {
        return match ($this) {
            self::PerPerson => 'Cost for one person × number of people',
            self::PerPersonPerDay => 'Cost for one person for one day × people × days',
            self::Fixed => 'One amount for the whole activity',
        };
    }

    /** Worked out from a cost per person (and a number of people). */
    public function isPerPerson(): bool
    {
        return $this !== self::Fixed;
    }
}

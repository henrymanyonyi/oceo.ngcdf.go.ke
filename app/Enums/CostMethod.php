<?php

namespace App\Enums;

/**
 * How a planned cost line is worked out (4 November 2026). Per person: the
 * cost for one person × the number of people (lunch at KES 4,000 for 10 people
 * is KES 40,000). Fixed: one amount for the whole activity, whatever the
 * number of people. A line with no method (entered before this) is fixed.
 */
enum CostMethod: string
{
    case PerPerson = 'per_person';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::PerPerson => 'Per person',
            self::Fixed => 'Fixed cost (per activity)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PerPerson => 'Per person',
            self::Fixed => 'Fixed',
        };
    }
}

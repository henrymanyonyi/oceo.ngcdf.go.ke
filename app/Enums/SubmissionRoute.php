<?php

namespace App\Enums;

/**
 * How a Head of Department sends a memo on (4 November 2026): through the
 * COS/ACOS office for review, then to the CEO; or directly to the CEO, with
 * the Chief of Staff and the Assistant taking no part in its review or
 * decision. A memo with no route recorded (submitted before this) follows
 * the review route.
 */
enum SubmissionRoute: string
{
    case Review = 'review';
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Through COS/ACOS',
            self::Direct => 'Direct to CEO',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Review => 'Head of Department → COS/ACOS review → CEO',
            self::Direct => 'Head of Department → CEO',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Review => 'bg-sky-50 text-sky-700',
            self::Direct => 'bg-amber-50 text-amber-700',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Review => 'fa-route',
            self::Direct => 'fa-arrow-right-to-bracket',
        };
    }
}

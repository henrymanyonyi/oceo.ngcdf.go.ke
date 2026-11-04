<?php

namespace App\Enums;

/**
 * FRD PT-02. A participant's role on the activity, in the order the choices
 * are offered (4 November 2026). Other asks for the role in words
 * (activity_participants.role_other; ActivityParticipant::roleLabel()).
 */
enum ParticipantRole: string
{
    case Director = 'director';
    case Ceo = 'ceo';
    case Hod = 'hod';
    case Officer = 'officer';
    case Support = 'support';
    case Driver = 'driver';
    case Other = 'other';
    case Member = 'member';
    case Lead = 'lead';

    public function label(): string
    {
        return match ($this) {
            self::Director => 'Director',
            self::Ceo => 'CEO',
            self::Hod => 'HOD',
            self::Officer => 'Officer',
            self::Support => 'Support staff',
            self::Driver => 'Driver',
            self::Other => 'Other (specify)',
            self::Member => 'Member',
            self::Lead => 'Team lead',
        };
    }

    /** Asks for the role in words. */
    public function requiresDetail(): bool
    {
        return $this === self::Other;
    }

    /** Where the role sorts in the memo's participant list: the most senior first. */
    public function rank(): int
    {
        return match ($this) {
            self::Ceo => 0,
            self::Director => 1,
            self::Hod => 2,
            self::Lead => 3,
            self::Officer, self::Member => 4,
            self::Other => 5,
            self::Support => 6,
            self::Driver => 7,
        };
    }

    /** Accepts the value or label, any case, as typed in an uploaded list. Blank means Member. */
    public static function fromInput(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return self::Member;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $value || strtolower($case->label()) === $value) {
                return $case;
            }
        }

        return match ($value) {
            'team leader', 'leader' => self::Lead,
            'head', 'head of department', 'head of dept' => self::Hod,
            'chief executive officer' => self::Ceo,
            'participant' => self::Member,
            'support', 'support staff' => self::Support,
            default => null,
        };
    }
}

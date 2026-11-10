<?php

namespace App\Enums;

/** What an officer signs a memo for, and the signature block it fills on the memo PDF. */
enum SigningAction: string
{
    case Prepared = 'prepared';
    case Reviewed = 'reviewed';
    case Validated = 'validated';
    case Approved = 'approved';

    /** The block's label on the memo, as in the template. */
    public function blockLabel(): string
    {
        return match ($this) {
            self::Prepared => 'Prepared by',
            self::Reviewed => 'Reviewed by',
            self::Validated => 'Validated by',
            self::Approved => 'Approved by',
        };
    }

    /** What the officer is about to do, for the confirmation popup and the code message. */
    public function verb(): string
    {
        return match ($this) {
            self::Prepared => 'submit and sign',
            self::Reviewed => 'review and forward',
            self::Validated => 'validate and forward',
            self::Approved => 'approve and authorise',
        };
    }
}

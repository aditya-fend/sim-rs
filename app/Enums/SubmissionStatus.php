<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * erd.md `chk_form_submissions_status`. LOCKED is terminal: the row is signed
 * by a DPJP and its clinical content is frozen by database trigger.
 */
enum SubmissionStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Locked = 'LOCKED';

    public function isLocked(): bool
    {
        return $this === self::Locked;
    }

    /** A locked record may still be read, exported and re-synced, never edited. */
    public function isMutable(): bool
    {
        return $this !== self::Locked;
    }
}

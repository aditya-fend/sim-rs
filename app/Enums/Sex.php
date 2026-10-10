<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * erd.md `chk_patients_gender`.
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';

    /** ui-spec.md `c-patient-strip` renders the single-letter form. */
    public function shortLabel(): string
    {
        return $this === self::Male ? 'L' : 'P';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * erd.md `chk_encounters_type`. The value doubles as the FHIR R4 ActCode class
 * for the resource (`EMER` / `IMP`), which FR-17 requires.
 */
enum EncounterType: string
{
    case Emergency = 'EMER';
    case Inpatient = 'IMP';
    case Outpatient = 'AMB';

    public function fhirClassCode(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Gawat Darurat (IGD)',
            self::Inpatient => 'Rawat Inap',
            self::Outpatient => 'Rawat Jalan',
        };
    }
}

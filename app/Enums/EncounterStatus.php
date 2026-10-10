<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * erd.md `chk_encounters_status`. The values are exactly the FHIR R4 Encounter
 * status code set required by FR-17, so the gateway can emit `status` verbatim.
 */
enum EncounterStatus: string
{
    case Arrived = 'arrived';
    case InProgress = 'in-progress';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function fhirStatus(): string
    {
        return $this->value;
    }
}

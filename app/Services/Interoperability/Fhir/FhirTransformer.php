<?php

declare(strict_types=1);

namespace App\Services\Interoperability\Fhir;

use App\Enums\EncounterType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * Maps internal records onto HL7 FHIR R4 resources for the SatuSehat gateway
 * (FR-16..FR-18, AC-06.1, AC-06.2).
 *
 * Transformers are kept apart from persistence on purpose (R-04): when
 * Kemenkes revises a mapping profile, only this namespace changes.
 *
 * References use the MoH master (IHS) identifiers rather than internal UUIDs,
 * which is what makes a payload acceptable to the national repository.
 */
final class FhirTransformer
{
    public function patient(Patient $patient): array
    {
        $resource = [
            'resourceType' => 'Patient',
            // The IHS id is the identity the national repository knows.
            'id' => $patient->ihs_patient_id ?? $patient->id,
            'identifier' => [
                [
                    'use' => 'official',
                    'system' => (string) Config::get('klinikform.fhir.patient_identifier_system'),
                    'value' => $patient->nik(),
                ],
                [
                    'use' => 'usual',
                    'system' => 'https://simrs.klinikform.local/mrn',
                    'value' => $patient->medical_record_number,
                ],
            ],
            'active' => true,
            'name' => [
                ['use' => 'official', 'text' => $patient->full_name],
            ],
            'gender' => $patient->gender->value,
            'birthDate' => $patient->birth_date->toDateString(),
        ];

        if ($patient->phone() !== null && $patient->phone() !== '') {
            $resource['telecom'] = [
                ['system' => 'phone', 'value' => $patient->phone(), 'use' => 'mobile'],
            ];
        }

        return $resource;
    }

    /**
     * AC-06.1 requires status, class, subject, participant, period and
     * serviceType.
     */
    public function encounter(Encounter $encounter): array
    {
        $patient = $encounter->patient;
        $dpjp = $encounter->dpjp;

        $resource = [
            'resourceType' => 'Encounter',
            'id' => $encounter->satusehat_encounter_id ?? $encounter->id,
            'identifier' => [
                [
                    'system' => (string) Config::get('klinikform.fhir.encounter_identifier_system'),
                    'value' => $encounter->encounter_number,
                ],
            ],
            'status' => $encounter->status->fhirStatus(),
            'class' => [
                'system' => (string) Config::get('klinikform.fhir.act_code_system'),
                'code' => $encounter->encounter_type->fhirClassCode(),
                'display' => $encounter->encounter_type === EncounterType::Emergency
                    ? 'emergency'
                    : ($encounter->encounter_type === EncounterType::Inpatient ? 'inpatient' : 'ambulatory'),
            ],
            'subject' => [
                'reference' => 'Patient/'.($patient?->ihs_patient_id ?? 'unknown'),
                'display' => $patient?->full_name,
            ],
            'period' => [
                'start' => $encounter->admission_time->toIso8601ZuluString(),
            ],
            'serviceType' => [
                [
                    'coding' => [
                        [
                            'system' => (string) Config::get('klinikform.fhir.icd10_system'),
                            'code' => $encounter->encounter_type->fhirClassCode(),
                            'display' => $encounter->encounter_type->label(),
                        ],
                    ],
                ],
            ],
        ];

        if ($encounter->discharge_time !== null) {
            $resource['period']['end'] = $encounter->discharge_time->toIso8601ZuluString();
        }

        if ($dpjp instanceof User) {
            $resource['participant'] = [
                [
                    'individual' => [
                        'reference' => 'Practitioner/'.($dpjp->ihs_practitioner_id ?? $dpjp->id),
                        'display' => $dpjp->name,
                    ],
                ],
            ];
        }

        return $resource;
    }

    /**
     * AC-06.2: a searchset Bundle of Condition resources with ICD-10 codings
     * under the HL7 SID system and clinicalStatus "active".
     *
     * @param  list<array{code: string|null, display: string|null}>  $diagnoses
     * @return array<string, mixed>
     */
    public function conditionBundle(Encounter $encounter, array $diagnoses): array
    {
        $patientReference = 'Patient/'.($encounter->patient?->ihs_patient_id ?? 'unknown');

        $entries = [];

        foreach ($diagnoses as $index => $diagnosis) {
            if (($diagnosis['code'] ?? null) === null || $diagnosis['code'] === '') {
                continue;
            }

            $entries[] = [
                'fullUrl' => sprintf('%s/Condition/%s-%d', $this->baseUrl(), $encounter->id, $index),
                'resource' => [
                    'resourceType' => 'Condition',
                    'id' => $encounter->id.'-'.$index,
                    'clinicalStatus' => [
                        'coding' => [
                            [
                                'system' => (string) Config::get('klinikform.fhir.clinical_status_system'),
                                'code' => 'active',
                            ],
                        ],
                    ],
                    'verificationStatus' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                                'code' => 'confirmed',
                            ],
                        ],
                    ],
                    'code' => [
                        'coding' => [
                            [
                                'system' => (string) Config::get('klinikform.fhir.icd10_system'),
                                'code' => (string) $diagnosis['code'],
                                'display' => $diagnosis['display'] ?? self::describe((string) $diagnosis['code']),
                            ],
                        ],
                    ],
                    'subject' => ['reference' => $patientReference],
                    'encounter' => ['reference' => 'Encounter/'.($encounter->satusehat_encounter_id ?? $encounter->id)],
                    'recordedDate' => $encounter->admission_time->toIso8601ZuluString(),
                ],
            ];
        }

        return [
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => count($entries),
            'entry' => $entries,
        ];
    }

    /**
     * Diagnosis rows sourced from locked submissions on the encounter.
     *
     * @return list<array{code: string|null, display: string|null}>
     */
    public function diagnosesFor(Encounter $encounter): array
    {
        return $encounter->submissions()
            ->whereNotNull('primary_icd10_code')
            ->get()
            ->map(static fn ($submission): array => [
                'code' => $submission->primary_icd10_code,
                'display' => self::describe((string) $submission->primary_icd10_code),
            ])
            ->all();
    }

    /**
     * Look a code up in the curated catalogue; fall back to the code itself so
     * an unmapped-but-valid diagnosis still transmits.
     */
    public static function describe(string $code): string
    {
        /** @var array<string, string> $catalogue */
        $catalogue = (array) config('icd10', []);

        return $catalogue[strtoupper($code)] ?? $code;
    }

    public static function isKnownCode(string $code): bool
    {
        /** @var array<string, string> $catalogue */
        $catalogue = (array) config('icd10', []);

        return isset($catalogue[strtoupper($code)]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Interoperability\Fhir\FhirTransformer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SatuSehat HL7 FHIR R4 gateway (FR-16..FR-18, AC-06.1, AC-06.2).
 *
 * Responses are bare FHIR resources, not the internal envelope: the consumer
 * is a national repository that validates against the FHIR schema, and
 * wrapping the body would break conformance.
 */
class FhirGatewayController extends Controller
{
    public function __construct(
        private readonly FhirTransformer $fhir = new FhirTransformer,
    ) {}

    /**
     * GET /api/v1/fhir/Patient/{id}
     *
     * Accepts either the internal UUID or the MoH IHS identifier.
     */
    public function patient(string $id): JsonResponse
    {
        $patient = Patient::query()
            ->where('id', $id)
            ->orWhere('ihs_patient_id', $id)
            ->first();

        if (! $patient instanceof Patient) {
            return ApiResponse::notFound('Patient not found.');
        }

        return response()->json($this->fhir->patient($patient));
    }

    /**
     * GET /api/v1/fhir/Encounter/{encounter_id}
     */
    public function encounter(string $encounterId): JsonResponse
    {
        $encounter = Encounter::query()
            ->with(['patient', 'dpjp'])
            ->whereKey($encounterId)
            ->first();

        if (! $encounter instanceof Encounter) {
            return ApiResponse::notFound('Encounter not found.');
        }

        return response()->json($this->fhir->encounter($encounter));
    }

    /**
     * POST /api/v1/fhir/Encounter
     *
     * FR-17: allows the national side to register an encounter this SIMRS did
     * not originate. Idempotent on the encounter number so a retried
     * registration does not duplicate the visit.
     */
    public function storeEncounter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier.value' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::enum(EncounterStatus::class)],
            'class.code' => ['required', Rule::enum(EncounterType::class)],
            'subject.reference' => ['required', 'string', 'max:128'],
            'period.start' => ['required', 'date'],
            'period.end' => ['nullable', 'date', 'after_or_equal:period.start'],
            'serviceType.coding.0.display' => ['nullable', 'string', 'max:128'],
        ]);

        $patientRef = (string) $validated['subject']['reference'];
        $patient = Patient::query()
            ->where('ihs_patient_id', $this->referenceId($patientRef))
            ->first();

        if (! $patient instanceof Patient) {
            return ApiResponse::notFound('Referenced patient is not registered in this SIMRS.');
        }

        $encounterNumber = (string) $validated['identifier']['value'];

        $existing = Encounter::query()->where('encounter_number', $encounterNumber)->first();

        if ($existing instanceof Encounter) {
            // Retrying a registration returns the original resource, not an
            // error: the caller cannot tell whether the first attempt landed.
            return response()->json($this->fhir->encounter($existing->load(['patient', 'dpjp'])));
        }

        $encounter = DB::transaction(function () use ($validated, $encounterNumber, $patient): Encounter {
            $period = (array) $validated['period'];

            return Encounter::create([
                'encounter_number' => $encounterNumber,
                'patient_id' => $patient->id,
                'encounter_type' => $validated['class']['code'],
                'status' => $validated['status'],
                'service_unit' => (string) (($validated['serviceType']['coding'][0]['display'] ?? 'IGD')),
                'admission_time' => $period['start'],
                'discharge_time' => $period['end'] ?? null,
            ]);
        });

        return response()->json(
            $this->fhir->encounter($encounter->load(['patient', 'dpjp'])),
            201,
        );
    }

    /**
     * GET /api/v1/fhir/Condition?encounter={encounter_id}
     */
    public function conditions(Request $request): JsonResponse
    {
        // api-spec.md: a missing query parameter is 400, not 404.
        $encounterId = $request->query('encounter');

        if (! is_string($encounterId) || $encounterId === '') {
            return ApiResponse::error(
                'BAD_REQUEST',
                'Parameter \'encounter\' is required.',
                [],
                400,
            );
        }

        $encounter = Encounter::query()
            ->with(['patient', 'dpjp'])
            ->whereKey($encounterId)
            ->first();

        if (! $encounter instanceof Encounter) {
            return ApiResponse::notFound('Encounter not found.');
        }

        return response()->json($this->fhir->conditionBundle($encounter, $this->fhir->diagnosesFor($encounter)));
    }

    /** "Patient/P00012345678" -> "P00012345678" */
    private function referenceId(string $reference): string
    {
        $parts = explode('/', $reference);

        return (string) end($parts);
    }
}

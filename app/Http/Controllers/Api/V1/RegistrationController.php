<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Registration endpoints.
 *
 * Neither appears in the original api-spec.md, which specifies patient lookup
 * but never how an encounter comes into existence — yet every form submission
 * hangs off one. Invented as the smallest coherent pair and documented as
 * endpoints 7 and 8 in api-spec.md.
 */
class RegistrationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/patients
     */
    public function storePatient(StorePatientRequest $request): JsonResponse
    {
        $patient = $request->patientAttributes();
        $patient->save();

        $this->audit->recordFor(AuditAction::Create, $patient, $request);

        return ApiResponse::created([
            'id' => $patient->id,
            'medical_record_number' => $patient->medical_record_number,
            'full_name' => $patient->full_name,
            'nik_masked' => $patient->maskedNik(),
        ], 'Patient berhasil didaftarkan.');
    }

    /**
     * GET /api/v1/encounters?patient_id=...&per_page=25
     */
    public function indexEncounters(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $encounters = Encounter::query()
            ->with(['patient', 'dpjp'])
            ->when($request->query('patient_id'), fn ($query, $id) => $query->where('patient_id', $id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('admission_time')
            ->paginate($perPage);

        return ApiResponse::success([
            'data' => collect($encounters->items())->map(fn (Encounter $encounter): array => [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
                'patient_id' => $encounter->patient_id,
                'patient_name' => $encounter->patient?->full_name,
                'encounter_type' => $encounter->encounter_type->value,
                'status' => $encounter->status->value,
                'service_unit' => $encounter->service_unit,
                'admission_time' => $encounter->admission_time->toIso8601ZuluString(),
            ])->all(),
            'meta' => [
                'page' => $encounters->currentPage(),
                'per_page' => $encounters->perPage(),
                'total' => $encounters->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/encounters
     */
    public function storeEncounter(Request $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $validated = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'encounter_type' => ['required', Rule::enum(EncounterType::class)],
            'service_unit' => ['required', 'string', 'max:64'],
            'dpjp_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'admission_time' => ['nullable', 'date'],
        ]);

        $encounter = Encounter::create([
            'encounter_number' => $this->nextEncounterNumber(),
            'patient_id' => $validated['patient_id'],
            'encounter_type' => $validated['encounter_type'],
            'service_unit' => $validated['service_unit'],
            'dpjp_user_id' => $validated['dpjp_user_id'] ?? null,
            'status' => EncounterStatus::Arrived,
            'admission_time' => $validated['admission_time'] ?? now(),
        ]);

        $this->audit->recordFor(AuditAction::Create, $encounter, $request);

        return ApiResponse::created([
            'id' => $encounter->id,
            'encounter_number' => $encounter->encounter_number,
            'status' => $encounter->status->value,
        ], 'Kunjungan berhasil dibuat.');
    }

    /**
     * Human-readable encounter code, e.g. ENC-202503-0012 (api-spec.md §4).
     */
    private function nextEncounterNumber(): string
    {
        $prefix = 'ENC-'.now()->format('Ym').'-';

        $latest = Encounter::query()
            ->where('encounter_number', 'like', $prefix.'%')
            ->orderByDesc('encounter_number')
            ->value('encounter_number');

        $sequence = $latest === null
            ? 1
            : ((int) Str::after((string) $latest, $prefix)) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}

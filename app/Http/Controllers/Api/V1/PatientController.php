<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use App\Services\Security\BlindIndexer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api-spec.md §1 — Patient Lookup by NIK.
 *
 * The only lookup path a nurse has for an existing patient, and the reason
 * the blind index exists: the column is encrypted, so the query is an indexed
 * equality on `nik_hash` rather than a decrypt-and-scan.
 */
class PatientController extends Controller
{
    public function __construct(
        private readonly BlindIndexer $blindIndexer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/patients/search?nik=3315012345670001
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $nik = (string) $request->query('nik', '');

        // api-spec.md error table: 422 for a malformed NIK, 404 when no match.
        if (! $this->blindIndexer->isValidNik($nik)) {
            return ApiResponse::validationError(
                ['nik' => ['NIK harus terdiri dari 16 digit angka.']],
            );
        }

        $patient = $this->blindIndexer->findPatient($nik);

        if (! $patient instanceof Patient) {
            return ApiResponse::notFound('Pasien dengan NIK tersebut tidak ditemukan.');
        }

        $this->audit->recordFor(AuditAction::View, $patient, $request);

        return ApiResponse::success($this->present($patient));
    }

    /**
     * GET /api/v1/patients/{id}
     */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $this->audit->recordFor(AuditAction::View, $patient, $request);

        return ApiResponse::success($this->present($patient));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'medical_record_number' => $patient->medical_record_number,
            'full_name' => $patient->full_name,
            'birth_date' => $patient->birth_date->toDateString(),
            'gender' => $patient->gender->value,
            // PII is masked by default; unmasked values are a separate grant.
            'nik_masked' => $patient->maskedNik(),
            'bpjs_card_number' => $patient->bpjs_card_number,
            'ihs_patient_id' => $patient->ihs_patient_id,
            'age_years' => $patient->ageInYears(),
        ];
    }
}

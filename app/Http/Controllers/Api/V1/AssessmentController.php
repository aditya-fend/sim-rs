<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\EncounterStatus;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncEncounterToSatuSehat;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Services\Audit\AuditLogger;
use App\Services\Security\SignOffService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * api-spec.md §3 — Sign and Lock Clinical Assessment (FR-11).
 *
 * The DPJP's signature is a SHA-256 over the payload, the signer and the
 * timestamp. Once applied, the row is immutable: the application policy
 * refuses further edits and a database trigger refuses them too, so the lock
 * holds even against a direct SQL session.
 */
class AssessmentController extends Controller
{
    public function __construct(
        private readonly SignOffService $signOff,
        private readonly AuditLogger $audit,
        private readonly WebhookDispatcher $webhooks = new WebhookDispatcher,
    ) {}

    /**
     * POST /api/v1/assessments/{submission_id}/sign
     */
    public function sign(Request $request, FormSubmission $submission): JsonResponse
    {
        // IT-04: a Triage Nurse attempting this must get 403, not 404 or 422.
        $this->authorize('sign', $submission);

        $validated = $request->validate([
            'primary_icd10_code' => ['required', 'string', 'max:16', 'regex:/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/'],
            'objective_findings' => ['nullable', 'string', 'max:3000'],
        ], [
            'primary_icd10_code.regex' => 'Format kode ICD-10 tidak valid. Contoh: K29.7',
            'primary_icd10_code.required' => 'Diagnosis utama ICD-10 wajib diisi.',
        ]);

        $signedAt = now();
        $signerId = (string) $this->currentUser($request)->id;

        $signature = DB::transaction(function () use ($submission, $validated, $signedAt, $signerId): string {
            // Re-check under the transaction: a concurrent sign must not slip
            // past the policy check that ran before the lock was taken.
            $locked = FormSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isLocked()) {
                throw new AlreadySignedException;
            }

            $locked->primary_icd10_code = strtoupper((string) $validated['primary_icd10_code']);
            $locked->objective_findings = $validated['objective_findings'] ?? null;

            // Recomputed over the payload *including* the diagnosis that was
            // just entered, so the signature covers the whole clinical record.
            $hash = $this->signOff->hash(
                $locked->id,
                $locked->payload_data,
                $signerId,
                $signedAt,
            );

            $locked->status = SubmissionStatus::Locked;
            $locked->signature_hash = $hash;
            $locked->signed_by = $signerId;
            $locked->signed_at = $signedAt;
            $locked->save();

            return $hash;
        });

        $this->audit->recordFor(AuditAction::Lock, $submission, $request);

        $encounter = $submission->encounter;
        if ($encounter instanceof Encounter) {
            $encounter->update(['status' => EncounterStatus::Finished]);

            // ADR-4: interoperability happens off the request path so a slow
            // or unreachable national gateway cannot delay the clinician.
            SyncEncounterToSatuSehat::dispatch($encounter->id);
        }

        $this->webhooks->dispatch('clinical.assessment.locked', [
            'submission_id' => $submission->id,
            'encounter_id' => $submission->encounter_id,
            'signature_hash' => $signature,
        ]);

        return ApiResponse::success([
            'submission_id' => $submission->id,
            'status' => SubmissionStatus::Locked->value,
            'signature_hash' => $signature,
            'signed_by' => $this->currentUser($request)->name,
            'signed_at' => $signedAt->toIso8601ZuluString(),
        ], 'Assessment successfully signed and permanently locked.');
    }

    /**
     * GET /api/v1/assessments/{submission_id}
     *
     * Verifies the stored signature on read, so a locked record that has been
     * altered outside the application is detected the moment it is opened.
     */
    public function show(Request $request, FormSubmission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        $this->audit->recordFor(AuditAction::View, $submission, $request);

        $intact = true;

        if ($submission->isLocked() && $submission->signature_hash !== null) {
            $intact = $this->signOff->verify(
                $submission->signature_hash,
                $submission->id,
                $submission->payload_data,
                (string) $submission->signed_by,
                $submission->signed_at,
            );
        }

        return ApiResponse::success([
            'submission_id' => $submission->id,
            'status' => $submission->status->value,
            'schema_version' => $submission->schema_version,
            'form_template_id' => $submission->form_template_id,
            'gcs_total' => $submission->gcs_total,
            'triage_category' => $submission->triage_category?->value,
            'primary_icd10_code' => $submission->primary_icd10_code,
            'objective_findings' => $submission->objective_findings,
            'payload_data' => $submission->payload_data,
            'anamnesis' => $submission->anamnesis(),
            'signature_hash' => $submission->signature_hash,
            'signed_by' => $submission->signer?->name,
            'signed_at' => $submission->signed_at?->toIso8601ZuluString(),
            'signature_verified' => $intact,
        ]);
    }
}

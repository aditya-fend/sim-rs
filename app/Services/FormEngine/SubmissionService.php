<?php

declare(strict_types=1);

namespace App\Services\FormEngine;

use App\Enums\AuditAction;
use App\Enums\SubmissionStatus;
use App\Enums\TriageCategory;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Clinical\GcsCalculator;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Persisting an intake submission (FR-10, AC-04.3, AC-08.1).
 *
 * Orchestration lives here rather than in the controller so the portal form,
 * the REST endpoint and any future batch import all take the same path: the
 * same encryption, the same draft purge, the same audit entry.
 */
final class SubmissionService
{
    public function __construct(
        private readonly DynamicValidator $validator = new DynamicValidator,
        private readonly GcsCalculator $gcs = new GcsCalculator,
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly WebhookDispatcher $webhooks = new WebhookDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Encounter $encounter,
        FormTemplate $template,
        array $payload,
        User $author,
        ?string $anamnesis,
        Request $request,
    ): FormSubmission {
        $patient = $encounter->patient;
        $context = [
            'gender' => $patient?->gender->value,
            'age' => $patient?->ageInYears(),
        ];

        // Validates against the schema bound to this encounter, pruning values
        // whose conditional_display is currently false.
        $validated = $this->validator->validateAndReturn($template, $payload, $context);

        return DB::transaction(function () use ($encounter, $template, $validated, $author, $anamnesis, $request): FormSubmission {
            $submission = new FormSubmission([
                'form_template_id' => $template->id,
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'authored_by' => $author->id,
                'schema_version' => $template->version,
                'status' => SubmissionStatus::Submitted,
                'payload_data' => $validated,
            ]);

            // FR-10: confidential narrative text is encrypted by the cast
            // before it reaches SQL.
            $submission->setAnamnesis($anamnesis);
            $submission->gcs_total = $this->gcsTotal($validated);
            $submission->triage_category = $this->triageCategory($validated);
            $submission->primary_icd10_code = $this->diagnosisCode($validated);

            $submission->save();

            // erd.md §5: a completed submission purges its server-side draft
            // immediately.
            $encounter->drafts()
                ->where('form_template_id', $template->id)
                ->where('user_id', $author->id)
                ->delete();

            $this->audit->recordFor(AuditAction::Create, $submission, $request);

            return $submission;
        });
    }

    /**
     * Recompute the GCS total server-side rather than trusting the client.
     * The widget already displayed it, but the stored value has to be derived
     * from the sub-scores by the same rule (FR-05).
     *
     * @param  array<string, mixed>  $payload
     */
    public function gcsTotal(array $payload): ?int
    {
        $gcs = $payload['gcs'] ?? null;

        if (! is_array($gcs) || ! $this->gcs->isComplete($gcs)) {
            return null;
        }

        return $this->gcs->calculate(
            (int) $gcs['eye'],
            (int) $gcs['verbal'],
            (int) $gcs['motor'],
        )['total'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function triageCategory(array $payload): ?TriageCategory
    {
        $value = $payload['triage_category'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return TriageCategory::tryFrom($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function diagnosisCode(array $payload): ?string
    {
        $value = $payload['primary_icd10_code'] ?? null;

        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }

    public function webhookDispatcher(): WebhookDispatcher
    {
        return $this->webhooks;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\FormEngine\SchemaRepository;
use App\Services\FormEngine\SubmissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api-spec.md §2 — Submit Clinical Intake Form.
 */
class SubmissionController extends Controller
{
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly SchemaRepository $schemas,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/encounters/{encounter_id}/submissions
     */
    public function store(Request $request, Encounter $encounter): JsonResponse
    {
        $this->authorize('create', FormSubmission::class);

        $validated = $request->validate([
            'form_template_id' => ['required_without:form_template_code', 'nullable', 'uuid', 'exists:form_templates,id'],
            'form_template_code' => ['required_without:form_template_id', 'nullable', 'string', 'max:64'],
            'schema_version' => ['nullable', 'integer', 'min:1'],
            'payload_data' => ['required', 'array'],
            'encrypted_anamnesis' => ['nullable', 'string', 'max:10000'],
        ], [
            'payload_data.required' => 'Data formulir wajib diisi.',
        ]);

        $template = $this->resolveTemplate($validated);

        if ($template === null) {
            return ApiResponse::notFound('Form template aktif tidak ditemukan.');
        }

        if ($template->is_active === false && ($validated['schema_version'] ?? null) !== $template->version) {
            // A historical submission may only be re-submitted against the
            // exact schema version it was captured under (AC-01.3).
            return ApiResponse::conflict(
                'Form template versi '.$template->version.' tidak cocok dengan versi skema yang diminta.',
            );
        }

        $submission = $this->submissions->record(
            $encounter,
            $template,
            $validated['payload_data'],
            $request->user(),
            $validated['encrypted_anamnesis'] ?? null,
            $request,
        );

        $this->submissions->webhookDispatcher()->dispatch('clinical.submission.created', [
            'submission_id' => $submission->id,
            'encounter_id' => $encounter->id,
            'form_template_code' => $template->code,
            'status' => $submission->status->value,
        ]);

        return ApiResponse::created([
            'submission_id' => $submission->id,
            'encounter_id' => $encounter->id,
            'status' => $submission->status->value,
            'schema_version' => $submission->schema_version,
            'gcs_total' => $submission->gcs_total,
            'triage_category' => $submission->triage_category?->value,
            'created_at' => $submission->created_at?->toIso8601ZuluString(),
        ], 'Clinical intake submission successfully saved.');
    }

    /**
     * GET /api/v1/encounters/{encounter_id}/submissions
     */
    public function index(Request $request, Encounter $encounter): JsonResponse
    {
        $this->authorize('view', FormSubmission::class);

        $submissions = $encounter->submissions()
            ->with(['template', 'author', 'signer'])
            ->latest()
            ->get();

        // Reading a record is itself an auditable event (AC-08.1).
        foreach ($submissions as $submission) {
            $this->audit->recordFor(AuditAction::View, $submission, $request);
        }

        return ApiResponse::success([
            'data' => $submissions->map(fn (FormSubmission $s): array => [
                'submission_id' => $s->id,
                'form_template_code' => $s->template?->code,
                'schema_version' => $s->schema_version,
                'status' => $s->status->value,
                'gcs_total' => $s->gcs_total,
                'triage_category' => $s->triage_category?->value,
                'primary_icd10_code' => $s->primary_icd10_code,
                'authored_by' => $s->author?->name,
                'signed_by' => $s->signer?->name,
                'signed_at' => $s->signed_at?->toIso8601ZuluString(),
                'payload_data' => $s->payload_data,
            ])->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveTemplate(array $validated): ?FormTemplate
    {
        if (isset($validated['form_template_id']) && $validated['form_template_id'] !== null) {
            return FormTemplate::query()->find($validated['form_template_id']);
        }

        $code = (string) $validated['form_template_code'];

        if (isset($validated['schema_version'])) {
            return FormTemplate::query()
                ->where('code', $code)
                ->where('version', $validated['schema_version'])
                ->first();
        }

        return $this->schemas->active($code);
    }
}

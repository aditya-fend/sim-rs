<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\AuditAction;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Services\Audit\AuditLogger;
use App\Services\Security\SignOffService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * user-flow.md §Flow 2 — the DPJP review and sign-off station.
 */
class DoctorController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * Encounters waiting for a physician assessment.
     */
    public function worklist(Request $request): View
    {
        $this->authorize('viewAny', FormSubmission::class);

        $encounters = Encounter::query()
            ->with(['patient', 'dpjp', 'submissions'])
            ->where('encounter_type', 'IMP')
            ->open()
            ->latest('admission_time')
            ->limit(50)
            ->get();

        return view('portal.doctor.worklist', ['encounters' => $encounters]);
    }

    /**
     * The assessment workspace: the nurse's decrypted findings alongside the
     * medical section the doctor completes.
     */
    public function assessment(Request $request, Encounter $encounter): View
    {
        $this->authorize('viewAny', FormSubmission::class);

        $encounter->load(['patient', 'dpjp']);

        $submissions = $encounter->submissions()
            ->with(['template', 'author', 'signer'])
            ->latest('id')
            ->get();

        // Reading the record is auditable in its own right (AC-08.1).
        foreach ($submissions as $submission) {
            $this->audit->recordFor(AuditAction::View, $submission, $request);
        }

        return view('portal.doctor.assessment', [
            'encounter' => $encounter,
            'patient' => $encounter->patient,
            'submissions' => $submissions,
            'unlocked' => $submissions->firstWhere(
                static fn (FormSubmission $s): bool => $s->status !== SubmissionStatus::Locked,
            ),
            // A URL template rather than a resolved one: the doctor picks which
            // submission to sign from the page.
            'signEndpoint' => url('/api/v1/assessments/{submission}/sign'),
            'icd10' => $this->icd10Options(),
        ]);
    }

    /**
     * Read-only view of a locked record with its Permenkes verification stamp.
     */
    public function record(Request $request, FormSubmission $submission): View
    {
        $this->authorize('view', $submission);

        $submission->load(['template', 'author', 'signer', 'encounter.patient']);

        $this->audit->recordFor(AuditAction::View, $submission, $request);

        $verified = $submission->signature_hash === null || app(SignOffService::class)
            ->verify(
                $submission->signature_hash,
                $submission->id,
                $submission->payload_data,
                (string) $submission->signed_by,
                $submission->signed_at,
            );

        return view('portal.doctor.record', [
            'submission' => $submission,
            'verified' => $verified,
        ]);
    }

    /**
     * Curated ICD-10 catalogue for the autocomplete picker: code -> description.
     *
     * @return array<string, string>
     */
    private function icd10Options(): array
    {
        /** @var array<string, string> $catalogue */
        $catalogue = (array) config('icd10', []);

        return $catalogue;
    }
}

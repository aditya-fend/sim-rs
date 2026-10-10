<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\EncounterType;
use App\Enums\TriageCategory;
use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\FormTemplate;
use App\Models\Patient;
use App\Services\FormEngine\SchemaRepository;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * user-flow.md §Flow 1 — the triage nurse's intake screen.
 *
 * Two steps: identify the patient, then fill the form rendered from the active
 * schema. The screen itself holds no clinical state; everything is submitted
 * to /api/v1/encounters/{id}/submissions.
 */
class NurseIntakeController extends Controller
{
    public function __construct(
        private readonly SchemaRepository $schemas = new SchemaRepository,
    ) {}

    /**
     * Patient identification. NIK is looked up through the blind index, so no
     * full-table decryption happens (AC-05.3).
     */
    public function index(Request $request): View
    {
        return view('portal.nurse.index', [
            'encounters' => Encounter::query()
                ->with('patient')
                ->open()
                ->latest('admission_time')
                ->limit(20)
                ->get(),
            'templates' => FormTemplate::query()->where('is_active', true)->get(),
            'initialQuery' => (string) $request->query('nik', ''),
        ]);
    }

    /**
     * The form itself, rendered from the encounter's active schema.
     */
    public function form(Request $request, Encounter $encounter): View
    {
        // Route-model binding has already loaded the patient, so the encounter's
        // own record is what is being authorised here.
        $this->authorize('view', $encounter);

        $encounter->load(['patient', 'dpjp']);

        $template = $encounter->encounter_type === EncounterType::Emergency
            ? $this->schemas->active('TRIAGE-ED-01')
            : $this->schemas->active('ASSESS-INPAT-01');

        abort_if($template === null, 404, 'Active form template is missing. Run the seeders.');

        return view('portal.nurse.form', [
            'encounter' => $encounter,
            'patient' => $encounter->patient,
            'template' => $template,
            'draft' => $encounter->drafts()->where('user_id', $this->currentUser($request)->id)->first(),
            'submissionEndpoint' => route('api.v1.submissions.store', $encounter),
            'draftEndpoint' => route('api.v1.drafts.upsert'),
            'triageCategories' => TriageCategory::cases(),
        ]);
    }
}

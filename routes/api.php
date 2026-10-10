<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\BpjsVClaimController;
use App\Http\Controllers\Api\V1\DraftController;
use App\Http\Controllers\Api\V1\FhirGatewayController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Middleware\BpjsSignatureAuth;
use App\Http\Middleware\EnsureFhirBearerToken;
use App\Http\Middleware\EnsureIdempotencyKey;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| KlinikForm SIMRS — /api/v1
|-------------------------------------------------------------------------------
| Two distinct audiences share this prefix:
|
|   Internal clinical REST (session auth)  — nurses, doctors, the portal UI
|   Interoperability gateways (token auth) — SatuSehat FHIR and BPJS VClaim
|
| The interoperability routes answer with bare FHIR / BPJS documents rather
| than the internal response envelope, because their consumers validate against
| those specifications.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    // --- Internal clinical API -------------------------------------------------
    // auth:web because api-spec.md §Conventions puts portal and bridging test
    // routes behind an authenticated session with CSRF; throttle:api applies
    // the 120 requests/minute budget.
    Route::middleware(['auth:web', 'throttle:api'])->group(function (): void {

        // 1. Patient lookup by NIK — the only reason the blind index exists.
        Route::get('/patients/search', [PatientController::class, 'search'])
            ->name('patients.search');

        // 7. Register a patient. Not in the original api-spec.md; see the
        //    note on StorePatientRequest.
        Route::post('/patients', [RegistrationController::class, 'storePatient'])
            ->middleware(EnsureIdempotencyKey::class)
            ->name('patients.store');

        Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');

        // 8. Encounters. Also not in the original api-spec.md.
        Route::get('/encounters', [RegistrationController::class, 'indexEncounters'])
            ->name('encounters.index');
        Route::post('/encounters', [RegistrationController::class, 'storeEncounter'])->name('encounters.store');

        // 2. Submit clinical intake form.
        Route::post('/encounters/{encounter}/submissions', [SubmissionController::class, 'store'])
            ->middleware(EnsureIdempotencyKey::class)
            ->name('submissions.store');
        Route::get('/encounters/{encounter}/submissions', [SubmissionController::class, 'index'])
            ->name('submissions.index');

        // 3. Sign and lock — DPJP only.
        Route::post('/assessments/{submission}/sign', [AssessmentController::class, 'sign'])
            ->name('assessments.sign');
        Route::get('/assessments/{submission}', [AssessmentController::class, 'show'])
            ->name('assessments.show');

        // 9. Server-side draft buffer (R-03).
        Route::put('/drafts', [DraftController::class, 'upsert'])->name('drafts.upsert');
        Route::get('/drafts/{encounter}/{template}', [DraftController::class, 'show'])->name('drafts.show');
        Route::delete('/drafts/{encounter}/{template}', [DraftController::class, 'destroy'])->name('drafts.destroy');
        Route::get('/encounters/{encounter}/drafts', [DraftController::class, 'indexForEncounter'])
            ->name('encounters.drafts');
    });

    // --- SatuSehat HL7 FHIR R4 gateway -----------------------------------------
    // FR-16..FR-18. Bearer token rather than a session: the national repository
    // is a machine caller.
    Route::middleware([EnsureFhirBearerToken::class, 'throttle:api'])
        ->prefix('fhir')
        ->name('fhir.')
        ->group(function (): void {
            Route::get('/Patient/{id}', [FhirGatewayController::class, 'patient'])->name('patient');
            Route::get('/Encounter/{encounterId}', [FhirGatewayController::class, 'encounter'])->name('encounter');
            Route::post('/Encounter', [FhirGatewayController::class, 'storeEncounter'])->name('encounter.store');
            Route::get('/Condition', [FhirGatewayController::class, 'conditions'])->name('condition');
        });

    // --- BPJS VClaim simulator --------------------------------------------------
    // FR-19..FR-21. HMAC handshake instead of a token.
    Route::middleware([BpjsSignatureAuth::class, 'throttle:api'])
        ->prefix('bpjs/vclaim')
        ->name('bpjs.vclaim.')
        ->group(function (): void {
            Route::post('/sep/insert', [BpjsVClaimController::class, 'insert'])->name('sep.insert');
        });
});

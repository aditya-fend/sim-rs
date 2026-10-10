<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SchemaManagerController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Compliance\AuditTrailController;
use App\Http\Controllers\Portal\DoctorController;
use App\Http\Controllers\Portal\HomeController;
use App\Http\Controllers\Portal\NurseIntakeController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Portal (session authenticated)
|-------------------------------------------------------------------------------
| These are the human-facing screens from user-flow.md §Navigation Map. The
| clinical data behind them is submitted to the /api/v1 endpoints, which stay
| the single write path regardless of which screen calls them.
|
*/

Route::redirect('/', '/portal')->name('root');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    // security.md: 5 attempts per minute per IP.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function (): void {

    Route::get('/portal', HomeController::class)->name('portal.home');

    // --- Nurse / Triage ------------------------------------------------------
    Route::middleware('can:createAny,App\Models\FormSubmission')->group(function (): void {
        Route::get('/portal/nurse/intake', [NurseIntakeController::class, 'index'])
            ->name('portal.nurse.intake');
        Route::get('/portal/nurse/intake/{encounter}', [NurseIntakeController::class, 'form'])
            ->name('portal.nurse.intake.form');
    });

    // --- Attending doctor / DPJP --------------------------------------------
    Route::get('/portal/doctor/worklist', [DoctorController::class, 'worklist'])
        ->name('portal.doctor.worklist');
    Route::get('/portal/doctor/assessment/{encounter}', [DoctorController::class, 'assessment'])
        ->name('portal.doctor.assessment');
    Route::get('/portal/doctor/record/{submission}', [DoctorController::class, 'record'])
        ->name('portal.doctor.record');

    // --- SIMRS deployment engineer ------------------------------------------
    Route::middleware('can:manage,App\Models\FormTemplate')->group(function (): void {
        Route::get('/admin/schemas', [SchemaManagerController::class, 'index'])->name('admin.schemas.index');
        Route::get('/admin/schemas/{template}', [SchemaManagerController::class, 'edit'])->name('admin.schemas.edit');
        Route::post('/admin/schemas/validate', [SchemaManagerController::class, 'validate'])
            ->name('admin.schemas.validate');
        Route::post('/admin/schemas/{template}/publish', [SchemaManagerController::class, 'publish'])
            ->name('admin.schemas.publish');
    });

    // --- Medical Records Officer --------------------------------------------
    Route::middleware('can:viewAny,App\Models\AuditLog')->group(function (): void {
        Route::get('/compliance/audit-trail', [AuditTrailController::class, 'index'])
            ->name('compliance.audit.index');
        Route::get('/compliance/audit-trail/export', [AuditTrailController::class, 'export'])
            ->name('compliance.audit.export');
    });
});

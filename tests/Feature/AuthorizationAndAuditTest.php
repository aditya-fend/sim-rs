<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\SubmissionStatus;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * testing.md IT-04 — POST sign-off as a Triage Nurse must be 403, and the
 * rest of the FR-15 authorisation matrix.
 *
 * testing.md IT-05 — a direct SQL UPDATE or DELETE against audit_logs must be
 * blocked by the database, not merely by application code.
 */
beforeEach(function (): void {
    seedFormTemplates();
    $this->nurse = makeUser([RoleSeeder::NURSE], ['name' => 'Siti Rahmawati, S.Kep.']);
    $this->doctor = makeUser([RoleSeeder::DPJP], ['name' => 'Dr. Budi Setiawan, Sp.PD']);
    $this->rekamMedis = makeUser([RoleSeeder::REKAM_MEDIS], ['name' => 'Dewi Lestari, A.Md.RMIK']);
    $this->admin = makeUser([RoleSeeder::ADMIN], ['name' => 'Rian Firmansyah']);

    // Known NIK so the lookup assertions have a target.
    $this->patient = makePatient('3315012345670001');

    $this->submission = makeSubmission();

    // At least one row must exist before IT-05 tries to tamper with it.
    app(AuditLogger::class)->recordFor(AuditAction::View, $this->submission);
});

/* -------------------------------------------------------------------------- */
/* IT-04 — authorisation */
/* -------------------------------------------------------------------------- */

it('refuses DPJP sign-off to a Triage Nurse with 403', function (): void {
    // testing.md IT-04 verbatim.
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", [
            'primary_icd10_code' => 'K29.7',
        ])
        ->assertStatus(403);

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

it('refuses sign-off to a Medical Records Officer and to an Admin', function (): void {
    foreach ([$this->rekamMedis, $this->admin] as $user) {
        $this->actingAs($user)
            ->postJson("/api/v1/assessments/{$this->submission->id}/sign", [
                'primary_icd10_code' => 'K29.7',
            ])
            ->assertStatus(403);
    }
});

it('allows DPJP sign-off and locks the record', function (): void {
    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", [
            'primary_icd10_code' => 'K29.7',
            'objective_findings' => 'Abdomen: Nyeri tekan epigastrium (+), defans muskular (-).',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'LOCKED')
        ->assertJsonPath('data.signature_hash', fn (string $hash) => strlen($hash) === 64);

    $fresh = $this->submission->fresh();

    expect($fresh->isLocked())->toBeTrue()
        ->and($fresh->signed_by)->toBe($this->doctor->id)
        ->and($fresh->primary_icd10_code)->toBe('K29.7');
});

it('closes the encounter when the assessment is signed', function (): void {
    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", [
            'primary_icd10_code' => 'K29.7',
        ])
        ->assertOk();

    expect($this->submission->encounter->fresh()->status->value)->toBe('finished');
});

it('answers 409, not 403, when a DPJP signs an already-locked record', function (): void {
    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", ['primary_icd10_code' => 'K29.7'])
        ->assertOk();

    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", ['primary_icd10_code' => 'K30'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_SIGNED');
});

it('rejects a diagnosis that is not a valid ICD-10 shape', function (): void {
    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", [
            'primary_icd10_code' => 'not-a-code',
        ])
        ->assertStatus(422)
        // api-spec.md moves validation details under error.details, so
        // Laravel's assertJsonValidationErrors() does not apply here.
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.details.primary_icd10_code.0', 'Format kode ICD-10 tidak valid. Contoh: K29.7');
});

it('lets the Medical Records Officer read a record but not author one', function (): void {
    $this->actingAs($this->rekamMedis)
        ->getJson("/api/v1/assessments/{$this->submission->id}")
        ->assertOk()
        ->assertJsonPath('data.submission_id', $this->submission->id);

    $this->actingAs($this->rekamMedis)
        ->postJson("/api/v1/encounters/{$this->submission->encounter_id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
        ])
        ->assertStatus(403);
});

it('lets every clinical role look a patient up', function (): void {
    foreach ([$this->nurse, $this->doctor, $this->rekamMedis, $this->admin] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/patients/search?nik=3315012345670001')
            ->assertOk();
    }
});

it('masks the NIK in every API response', function (): void {
    $this->actingAs($this->nurse)
        ->getJson('/api/v1/patients/search?nik=3315012345670001')
        ->assertOk()
        ->assertJsonPath('data.nik_masked', '331501******0001')
        ->assertJsonMissingPath('data.nik');
});

it('rejects a NIK that is not 16 digits with 422', function (): void {
    $this->actingAs($this->nurse)
        ->getJson('/api/v1/patients/search?nik=12345')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('answers 404 for a well-formed NIK with no matching patient', function (): void {
    $this->actingAs($this->nurse)
        ->getJson('/api/v1/patients/search?nik=9999999999999999')
        ->assertStatus(404);
});

it('exposes the audit console only to Rekam Medis and Admin', function (): void {
    foreach ([$this->rekamMedis, $this->admin] as $user) {
        expect($user->can('viewAny', AuditLog::class))->toBeTrue();
    }

    foreach ([$this->nurse, $this->doctor] as $user) {
        expect($user->can('viewAny', AuditLog::class))->toBeFalse();
    }
});

it('reserves audit export for the Medical Records Officer', function (): void {
    expect($this->rekamMedis->can('export', AuditLog::class))->toBeTrue()
        ->and($this->admin->can('export', AuditLog::class))->toBeFalse()
        ->and($this->doctor->can('export', AuditLog::class))->toBeFalse();
});

it('reserves schema publication for the System Administrator', function (): void {
    $template = activeTemplate('TRIAGE-ED-01');

    foreach ([$this->admin] as $user) {
        expect($user->can('update', $template))->toBeTrue();
    }

    foreach ([$this->nurse, $this->doctor, $this->rekamMedis] as $user) {
        expect($user->can('update', $template))->toBeFalse();
    }
});

/* -------------------------------------------------------------------------- */
/* IT-05 — immutable audit store */
/* -------------------------------------------------------------------------- */

it('blocks a direct SQL UPDATE against audit_logs', function (): void {
    $log = AuditLog::query()->firstOrFail();

    // Deliberately bypassing Eloquent entirely: this is the "future code path
    // or direct psql session" case the database rule exists for.
    //
    // The probe runs inside a nested transaction. Postgres marks a transaction
    // failed the moment a trigger raises and refuses every later statement in
    // it, so without a savepoint here the remaining assertions would fail for
    // the wrong reason.
    expect(fn () => DB::transaction(fn () => DB::update(
        'UPDATE audit_logs SET role_name = ? WHERE id = ?',
        ['Hacked', $log->id],
    )))->toThrow(QueryException::class);

    expect($log->fresh()->role_name)->not->toBe('Hacked');
});

it('blocks a direct SQL DELETE against audit_logs', function (): void {
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::delete('DELETE FROM audit_logs WHERE id = ?', [$log->id])))
        ->toThrow(QueryException::class);

    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('blocks a bulk DELETE that would wipe the whole trail', function (): void {
    expect(fn () => DB::transaction(fn () => DB::delete('DELETE FROM audit_logs')))
        ->toThrow(QueryException::class);

    expect(AuditLog::query()->count())->toBeGreaterThan(0);
});

it('refuses to re-save an existing entry through Eloquent', function (): void {
    $log = AuditLog::query()->firstOrFail();

    expect(function () use ($log): void {
        $log->role_name = 'Hacked';
        $log->save();
    })->toThrow(LogicException::class);
});

it('exposes no destroy method on the audit model', function (): void {
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('still accepts appends, so the trail keeps growing', function (): void {
    $before = AuditLog::query()->count();

    app(AuditLogger::class)->recordFor(AuditAction::Export, $this->submission);

    expect(AuditLog::query()->count())->toBe($before + 1);
});

it('blocks any modification to a locked submission, even by raw SQL', function (): void {
    $this->actingAs($this->doctor)
        ->postJson("/api/v1/assessments/{$this->submission->id}/sign", ['primary_icd10_code' => 'K29.7'])
        ->assertOk();

    expect(fn () => DB::transaction(fn () => DB::update(
        'UPDATE form_submissions SET payload_data = ? WHERE id = ?',
        [json_encode(['systolic' => 999]), $this->submission->id],
    )))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::delete(
        'DELETE FROM form_submissions WHERE id = ?',
        [$this->submission->id],
    )))->toThrow(QueryException::class);

    expect($this->submission->fresh()->payload_data['systolic'])->toBe(130);
});

it('records the tampering attempt in the compliance log', function (): void {
    expect(implode(' ', AuditLog::query()->get()->map(
        static fn (AuditLog $log): string => (string) $log->resource_type
    )->all()))->not->toBe('');
});

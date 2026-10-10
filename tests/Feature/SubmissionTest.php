<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\AuditLog;
use App\Models\FormSubmission;
use App\Models\Patient;
use App\Services\FormEngine\SchemaRepository;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

/**
 * testing.md IT-01 / AC-01.2 — POST /api/v1/encounters/{id}/submissions
 * with systolic = 350 must be rejected with HTTP 422 and a message naming the
 * violated boundary.
 */
beforeEach(function (): void {
    seedFormTemplates();
    $this->nurse = makeUser([RoleSeeder::NURSE], ['name' => 'Siti Rahmawati, S.Kep.']);
    // Explicit NIK: the last test in this file looks the patient up by it.
    $this->patient = makePatient('3315012345670001');
    $this->encounter = makeEncounter($this->patient);
});

it('rejects a systolic pressure above the schema ceiling with HTTP 422', function (): void {
    $response = $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['systolic' => 350]),
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.details.systolic.0', 'Nilai sistol harus di antara 40 – 300 mmHg.');

    expect(FormSubmission::query()->count())->toBe(0);
});

it('rejects a systolic pressure below the schema floor', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['systolic' => 15]),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.systolic.0', 'Nilai sistol harus di antara 40 – 300 mmHg.');
});

it('accepts a payload inside the clinical bounds and returns HTTP 201', function (): void {
    $response = $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_id' => activeTemplate('TRIAGE-ED-01')->id,
            'schema_version' => 1,
            'payload_data' => triagePayload(),
            'encrypted_anamnesis' => 'Nyeri ulu hati sejak 2 hari.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'SUBMITTED');

    expect(FormSubmission::query()->count())->toBe(1);
});

it('stores the GCS total, triage category and pain markers it received', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
        ])
        ->assertCreated();

    $submission = FormSubmission::query()->firstOrFail();

    expect($submission->gcs_total)->toBe(12)
        ->and($submission->triage_category->value)->toBe('urgent')
        ->and($submission->payload_data['pain_markers'][0])
        ->toMatchArray(['region' => 'epigastrium', 'severity' => 7]);
});

it('encrypts the anamnesis so no plaintext reaches the column', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
            'encrypted_anamnesis' => 'Nyeri ulu hati sejak 2 hari yang lalu.',
        ])
        ->assertCreated();

    $raw = DB::table('form_submissions')->value('encrypted_anamnesis');

    expect($raw)->toStartWith('v1.')
        ->and($raw)->not->toContain('Nyeri ulu hati')
        ->and(FormSubmission::query()->firstOrFail()->anamnesis())->toBe('Nyeri ulu hati sejak 2 hari yang lalu.');
});

it('lifts a confidential field out of payload_data into the encrypted column', function (): void {
    // The portal posts the whole form as payload_data, so the confidential
    // flag in the schema is the only thing stopping anamnesis_notes from
    // being stored as plain JSON (FR-10, AC-05.1).
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['anamnesis_notes' => 'Asidosis. Diabetes tipe 2.']),
        ])
        ->assertCreated();

    $rawPayload = DB::table('form_submissions')->value('payload_data');
    $rawAnamnesis = DB::table('form_submissions')->value('encrypted_anamnesis');

    expect($rawPayload)->not->toContain('anamnesis_notes')
        ->and($rawPayload)->not->toContain('Asidosis')
        ->and($rawAnamnesis)->toStartWith('v1.')
        ->and(FormSubmission::query()->firstOrFail()->payload_data)->not->toHaveKey('anamnesis_notes')
        ->and(FormSubmission::query()->firstOrFail()->anamnesis())->toContain('Asidosis');
});

it('recomputes the GCS total server-side instead of trusting the client', function (): void {
    // A tampered client claims total 15 while its sub-scores sum to 12.
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload([
                'gcs' => ['eye' => 3, 'verbal' => 4, 'motor' => 5, 'total' => 15, 'category' => 'compos_mentis'],
            ]),
        ])
        ->assertCreated();

    expect(FormSubmission::query()->firstOrFail()->gcs_total)->toBe(12);
});

it('does not require a conditional field whose condition is not met', function (): void {
    // travel_endemic_area is required only when temperature >= 38.
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['body_temperature' => 37.1]),
        ])
        ->assertCreated();

    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['body_temperature' => 39.2]),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.travel_endemic_area.0', 'Jawab riwayat perjalanan bila suhu tubuh ≥ 38 °C.');
});

it('drops the value of a field that became hidden rather than persisting it', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            // Temperature is below the threshold, so the answer must be pruned.
            'payload_data' => triagePayload(['body_temperature' => 36.5, 'travel_endemic_area' => 'yes']),
        ])
        ->assertCreated();

    expect(FormSubmission::query()->firstOrFail()->payload_data)
        ->not->toHaveKey('travel_endemic_area');
});

it('rejects a value outside the enumerated option set', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(['triage_category' => 'catastrophic']),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['triage_category']]]);
});

it('rejects pain coordinates outside 0-100', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload([
                'pain_markers' => [['x' => 480, 'y' => 32.5, 'region' => 'chest', 'severity' => 4]],
            ]),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['pain_markers.0.x']]]);
});

it('refuses the submission when the nurse is not authenticated', function (): void {
    $this->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
        'form_template_code' => 'TRIAGE-ED-01',
        'payload_data' => triagePayload(),
    ])->assertStatus(401);
});

it('refuses the submission for a role that may not author clinical forms', function (): void {
    // FR-15: Medical Records Officer is read-only on clinical content.
    $rekamMedis = makeUser([RoleSeeder::REKAM_MEDIS]);

    $this->actingAs($rekamMedis)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
        ])
        ->assertStatus(403);
});

it('writes an immutable CREATE audit row for every accepted submission', function (): void {
    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
        ])
        ->assertCreated();

    $audit = AuditLog::query()->where('action', 'CREATE')->firstOrFail();

    expect($audit->resource_type)->toBe(FormSubmission::class)
        ->and($audit->role_name)->toBe('Triage Nurse')
        ->and($audit->user_id)->toBe($this->nurse->id)
        ->and($audit->payload_checksum)->toHaveLength(64)
        ->and($audit->ip_address)->not->toBeEmpty();
});

it('purges the server-side draft once the submission is stored', function (): void {
    $template = activeTemplate('TRIAGE-ED-01');

    $this->actingAs($this->nurse)->putJson('/api/v1/drafts', [
        'encounter_id' => $this->encounter->id,
        'form_template_id' => $template->id,
        'draft_payload' => ['systolic' => 130],
    ])->assertOk();

    expect($this->encounter->drafts()->count())->toBe(1);

    $this->actingAs($this->nurse)
        ->postJson("/api/v1/encounters/{$this->encounter->id}/submissions", [
            'form_template_code' => 'TRIAGE-ED-01',
            'payload_data' => triagePayload(),
        ])
        ->assertCreated();

    expect($this->encounter->drafts()->count())->toBe(0);
});

it('replays the cached 201 for a repeated X-Idempotency-Key', function (): void {
    $key = (string) Str::uuid();
    $endpoint = "/api/v1/encounters/{$this->encounter->id}/submissions";
    $body = ['form_template_code' => 'TRIAGE-ED-01', 'payload_data' => triagePayload()];

    $first = $this->actingAs($this->nurse)->withHeader('X-Idempotency-Key', $key)->postJson($endpoint, $body);
    $second = $this->actingAs($this->nurse)->withHeader('X-Idempotency-Key', $key)->postJson($endpoint, $body);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('X-Idempotent-Replay', 'true');

    // The point of the feature: one clinical write, not two.
    expect(FormSubmission::query()->count())->toBe(1)
        ->and($first->json('data.submission_id'))->toBe($second->json('data.submission_id'));
});

it('does not cache a failed attempt under an idempotency key', function (): void {
    $key = (string) Str::uuid();
    $endpoint = "/api/v1/encounters/{$this->encounter->id}/submissions";

    $this->actingAs($this->nurse)->withHeader('X-Idempotency-Key', $key)->postJson($endpoint, [
        'form_template_code' => 'TRIAGE-ED-01',
        'payload_data' => triagePayload(['systolic' => 350]),
    ])->assertStatus(422);

    // A caller whose connection dropped must be able to retry under the same key.
    $this->actingAs($this->nurse)->withHeader('X-Idempotency-Key', $key)->postJson($endpoint, [
        'form_template_code' => 'TRIAGE-ED-01',
        'payload_data' => triagePayload(),
    ])->assertCreated();
});

it('keeps patient NIK encrypted through the whole intake path', function (): void {
    $this->actingAs($this->nurse)
        ->getJson('/api/v1/patients/search?nik=3315012345670001')
        ->assertOk()
        ->assertJsonPath('data.nik_masked', '331501******0001');

    $raw = DB::table('patients')->where('id', $this->patient->id)->value('nik_encrypted');

    expect($raw)->not->toContain('3315012345670001')
        ->and(Patient::query()->find($this->patient->id)->nik())->toBe('3315012345670001');
});

it('binds a submission to the schema version it was captured under', function (): void {
    // AC-01.3 / R-02: publishing v2 must not change how v1 submissions render.
    $admin = makeUser([RoleSeeder::ADMIN]);
    $repository = app(SchemaRepository::class);
    $v1 = activeTemplate('TRIAGE-ED-01');

    $repository->publish(
        'TRIAGE-ED-01',
        'Formulir Triase Gawat Darurat (revisi 2)',
        $v1->schema_definition,
        $admin,
    );

    expect(activeTemplate('TRIAGE-ED-01')->version)->toBe($v1->version + 1)
        ->and($v1->fresh()->is_active)->toBeFalse()
        ->and($v1->fresh()->schema_definition)->toBe($v1->schema_definition);
});

it('never lets a locked submission be modified through the application', function (): void {
    $doctor = makeUser([RoleSeeder::DPJP]);
    $submission = makeSubmission();

    $this->actingAs($doctor)->postJson("/api/v1/assessments/{$submission->id}/sign", [
        'primary_icd10_code' => 'K29.7',
        'objective_findings' => 'Abdomen: Nyeri tekan epigastrium (+), defans muskular (-).',
    ])->assertOk();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Locked);

    $this->actingAs($doctor)->postJson("/api/v1/assessments/{$submission->id}/sign", [
        'primary_icd10_code' => 'K30',
    ])->assertStatus(409);
});

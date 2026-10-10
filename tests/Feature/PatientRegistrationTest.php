<?php

declare(strict_types=1);

use App\Enums\Sex;
use App\Models\Patient;
use App\Services\Security\BlindIndexer;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Patient registration is the entry point of every intake (user-flow.md §Flow
 * 1): the nurse arrives with a walk-in and no record. The NIK is the blind
 * index key, so the request is the place where a malformed or duplicate one
 * must be refused.
 */
beforeEach(function (): void {
    $this->nurse = makeUser([RoleSeeder::NURSE]);
});

function patientPayload(array $overrides = []): array
{
    return array_merge([
        'medical_record_number' => '00-99-01',
        'full_name' => 'Ratna Sari',
        'birth_date' => '1990-04-17',
        'gender' => Sex::Female->value,
        'nik' => '3315029876540002',
        'phone' => '081234567890',
        'address' => 'Jl. Merdeka No. 10',
        'bpjs_card_number' => '0001234567890',
        'ihs_patient_id' => 'IHS-0001',
    ], $overrides);
}

it('registers a walk-in and returns only the masked NIK', function (): void {
    $response = $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload())
        ->assertCreated();

    $response->assertJsonPath('data.nik_masked', '331502******0002')
        ->assertJsonMissingPath('data.nik');

    $patient = Patient::query()->firstOrFail();

    expect($patient->full_name)->toBe('Ratna Sari')
        ->and($patient->maskedNik())->not->toContain('3315029876540002');
});

it('stores the NIK encrypted and derives the blind index from it', function (): void {
    $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload())
        ->assertCreated();

    $patient = Patient::query()->firstOrFail();
    $raw = DB::table('patients')->where('id', $patient->id)->first();

    expect((string) $raw->nik_encrypted)->toStartWith('v1.')
        ->and((string) $raw->nik_encrypted)->not->toContain('3315029876540002')
        // The lookup hash must answer for the same NIK that is stored.
        ->and(app(BlindIndexer::class)->findPatient('3315029876540002')?->id)
        ->toBe($patient->id);
});

it('refuses a NIK that is not exactly 16 digits', function (): void {
    // Errors ride in the api-spec.md envelope, not Laravel's default key.
    $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload(['nik' => '33150']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.details.nik.0', 'NIK harus terdiri dari 16 digit angka.');

    expect(Patient::query()->count())->toBe(0);
});

it('refuses a duplicate medical record number', function (): void {
    makePatient('3315029876540009', ['medical_record_number' => '00-99-01']);

    $response = $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($response->json('error.details.medical_record_number.0'))->toContain('sudah');
});

it('refuses a birth date in the future', function (): void {
    $response = $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload(['birth_date' => now()->addDay()->toDateString()]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($response->json('error.details.birth_date.0'))
        ->toBe('Tanggal lahir tidak boleh di masa depan.');
});

it('names the field in Indonesian rather than as a raw key', function (): void {
    // attributes() exists so the message reads "tanggal lahir", not
    // "birth_date" — a nurse should never be shown an English key.
    $response = $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload(['birth_date' => null]))
        ->assertStatus(422);

    $message = (string) $response->json('error.details.birth_date.0');

    expect($message)->toContain('tanggal lahir')->not->toContain('birth_date');
});

it('refuses a BPJS card number that is not 13 digits', function (): void {
    $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload(['bpjs_card_number' => '123']))
        ->assertStatus(422)
        ->assertJsonPath('error.details.bpjs_card_number.0', 'Nomor kartu BPJS harus terdiri dari 13 digit angka.');
});

it('accepts a patient with no BPJS card or IHS id', function (): void {
    $this->actingAs($this->nurse)
        ->postJson('/api/v1/patients', patientPayload([
            'bpjs_card_number' => null,
            'ihs_patient_id' => null,
            'phone' => null,
            'address' => null,
        ]))
        ->assertCreated();

    $patient = Patient::query()->firstOrFail();

    expect($patient->bpjs_card_number)->toBeNull()
        ->and($patient->maskedNik())->toStartWith('331502');
});

it('refuses a registration from a role without the permission', function (): void {
    // IT-04 pattern: an unauthorised role must get 403, not a validation pass.
    $this->actingAs(makeUser([RoleSeeder::REKAM_MEDIS]))
        ->postJson('/api/v1/patients', patientPayload())
        ->assertForbidden();
});

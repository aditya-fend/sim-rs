<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\BpjsSepRecord;
use App\Services\Interoperability\Bpjs\BpjsSignature;

/**
 * testing.md IT-03 — POST /api/v1/bpjs/vclaim/sep/insert with an X-timestamp
 * drifted 400 seconds must be rejected with HTTP 401 and the exact BPJS body.
 */
beforeEach(function (): void {
    // Card eligibility in this simulator is decided by the configured
    // inactive_card_final_digit; the fixture therefore has to end in something
    // other than '0' for the happy path.
    $this->activeCard = '0001234567891';
    $this->inactiveCard = '0001234567890';

    $this->patient = makePatient('3315012345670001', ['bpjs_card_number' => $this->activeCard]);
    $this->encounter = makeEncounter($this->patient);

    $this->payload = [
        't_sep' => [
            'noKartu' => $this->activeCard,
            'tglSep' => '2025-03-01',
            'ppkPelayanan' => '0301R001',
            'jnsPelayanan' => '2',
            'klsRawat' => ['klsRawatHak' => '3'],
            'noMR' => '00-48-21',
            'rujukan' => [
                'asalRujukan' => '1',
                'tglRujukan' => '2025-02-28',
                'noRujukan' => 'RJV-00921',
                'ppkRujukan' => '0301F002',
            ],
            'catatan' => 'Pasien nyeri ulu hati akut',
            'diagAwal' => 'K29.7',
            'poli' => ['tujuan' => 'IGD', 'eksekutif' => '0'],
            'cob' => ['cob' => '0'],
            'katarak' => ['katarak' => '0'],
            'jknKeluarga' => ['anakKe' => '0', 'tertanggung' => '0'],
            'noTelp' => '081234567890',
            'user' => 'SimrsAdmin',
        ],
    ];
});

/**
 * Build a valid BPJS handshake, optionally with a drifted clock.
 *
 * @return array<string, string>
 */
function bpjsHeaders(?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = new BpjsSignature;

    return [
        'X-cons-id' => (string) config('klinikform.bpjs.cons_id'),
        'X-timestamp' => (string) $timestamp,
        'X-signature' => $signature->sign(
            (string) config('klinikform.bpjs.cons_id'),
            (string) config('klinikform.bpjs.secret_key'),
            $timestamp,
        ),
        'user_key' => (string) config('klinikform.bpjs.user_key'),
        'X-Encounter-Id' => test()->encounter->id,
    ];
}

it('rejects a timestamp drifted 400 seconds with the exact BPJS error body', function (): void {
    // AC-07.3 / FR-20, verbatim.
    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders(time() - 400))
        ->assertStatus(401)
        ->assertExactJson([
            'metaData' => [
                'code' => '401',
                'message' => 'Timestamp kadaluarsa.',
            ],
        ]);
});

it('rejects a timestamp drifted forward by the same window', function (): void {
    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders(time() + 400))
        ->assertStatus(401)
        ->assertJsonPath('metaData.message', 'Timestamp kadaluarsa.');
});

it('accepts a timestamp at the edge of the window', function (): void {
    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders(time() - 295))
        ->assertStatus(201);
});

it('rejects a request with a forged signature', function (): void {
    $headers = bpjsHeaders();
    $headers['X-signature'] = base64_encode('forged');

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('metaData.message', 'Signature tidak valid.');
});

it('rejects a request that omits the handshake headers entirely', function (): void {
    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload)
        ->assertStatus(401)
        ->assertJsonPath('metaData.message', 'Header X-cons-id, X-timestamp, dan X-signature wajib diisi.');
});

it('rejects an unknown ConsID', function (): void {
    $headers = bpjsHeaders();
    $headers['X-cons-id'] = '9999999999';

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, $headers)
        ->assertStatus(401)
        ->assertJsonPath('metaData.message', 'ConsID tidak dikenal.');
});

it('generates a SEP matching the AC-07.2 shape and persists the transaction', function (): void {
    $response = $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('metaData.code', '200')
        ->assertJsonPath('metaData.message', 'Sukses')
        ->assertJsonPath('response.sep.nama', 'Joko Susanto')
        ->assertJsonPath('response.sep.poli', 'IGD')
        ->assertJsonPath('response.sep.catatan', 'Pasien nyeri ulu hati akut');

    $sepNumber = $response->json('response.sep.noSep');

    // faskes code + MMYY + 'B' + a 7-digit counter, per api-spec.md's example.
    expect($sepNumber)->toMatch('/^0301R001\d{4}B\d{7}$/');

    $record = BpjsSepRecord::query()->firstOrFail();
    expect($record->sep_number)->toBe($sepNumber)
        ->and($record->encounter_id)->toBe($this->encounter->id)
        ->and($record->signature_used)->not->toBeEmpty()
        ->and($record->response_payload['metaData']['code'])->toBe('200');
});

it('issues a distinct SEP number for each successive claim', function (): void {
    $first = $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders())
        ->assertStatus(201)->json('response.sep.noSep');

    $second = $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders())
        ->assertStatus(201)->json('response.sep.noSep');

    expect($first)->not->toBe($second)
        ->and(BpjsSepRecord::query()->count())->toBe(2);
});

it('answers BPJS code 201 when the card is not active', function (): void {
    // user-flow.md §Flow 4 branches on card validity.
    $payload = $this->payload;
    $payload['t_sep']['noKartu'] = $this->inactiveCard;

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $payload, bpjsHeaders())
        ->assertStatus(200)
        ->assertJsonPath('metaData.code', '201')
        ->assertJsonPath('metaData.message', 'Peserta Tidak Aktif');

    expect(BpjsSepRecord::query()->count())->toBe(0);
});

it('validates the mandatory BPJS parameters', function (): void {
    $payload = $this->payload;
    unset($payload['t_sep']['noKartu'], $payload['t_sep']['diagAwal']);

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $payload, bpjsHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['t_sep.noKartu', 't_sep.diagAwal']);
});

it('rejects a BPJS card number that is not 13 digits', function (): void {
    $payload = $this->payload;
    $payload['t_sep']['noKartu'] = '12345';

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $payload, bpjsHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['t_sep.noKartu']);
});

it('returns 404 when the encounter header does not resolve', function (): void {
    $headers = bpjsHeaders();
    $headers['X-Encounter-Id'] = '00000000-0000-4000-8000-000000000000';

    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, $headers)
        ->assertStatus(404)
        ->assertJsonPath('metaData.code', '404');
});

it('records the handshake in the immutable audit trail', function (): void {
    $this->postJson('/api/v1/bpjs/vclaim/sep/insert', $this->payload, bpjsHeaders())
        ->assertStatus(201);

    $audit = AuditLog::query()->where('action', 'CREATE')->firstOrFail();

    expect($audit->resource_type)->toBe(BpjsSepRecord::class)
        ->and($audit->payload_checksum)->toHaveLength(64);
});

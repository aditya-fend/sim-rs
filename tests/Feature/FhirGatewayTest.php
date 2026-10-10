<?php

declare(strict_types=1);

use App\Models\Encounter;
use Database\Seeders\RoleSeeder;

/**
 * testing.md IT-02 — GET /api/v1/fhir/Encounter/{id} returns a valid HL7
 * FHIR R4 resource, and AC-06.1/AC-06.2 require specific elements.
 */
beforeEach(function (): void {
    $this->token = (string) config('klinikform.fhir.token');
    $this->doctor = makeUser([RoleSeeder::DPJP], [
        'name' => 'Dr. Budi Setiawan, Sp.PD',
        'sip_number' => '446/120/IP.D/2023',
        'ihs_practitioner_id' => 'DR987654321',
    ]);

    $this->patient = makePatient('3315012345670001', [
        'full_name' => 'Tn. Joko Susanto',
        'ihs_patient_id' => 'P00012345678',
    ]);

    $this->encounter = makeEncounter($this->patient, [
        'encounter_number' => 'ENC-202503-0012',
        'dpjp_user_id' => $this->doctor->id,
        'encounter_type' => 'EMER',
        'status' => 'finished',
        'admission_time' => now()->subHour(),
        'discharge_time' => now(),
    ]);
});

function fhirGet(string $path)
{
    return test()->withHeader('Authorization', 'Bearer '.config('klinikform.fhir.token'))
        ->getJson('/api/v1/fhir'.$path);
}

it('rejects a request with no bearer token', function (): void {
    $this->getJson("/api/v1/fhir/Encounter/{$this->encounter->id}")
        ->assertStatus(401);
});

it('rejects a request with a wrong bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer not-the-token')
        ->getJson("/api/v1/fhir/Encounter/{$this->encounter->id}")
        ->assertStatus(401);
});

it('returns the Encounter resource with every AC-06.1 element present', function (): void {
    $response = fhirGet("/Encounter/{$this->encounter->id}");

    $response->assertOk()
        ->assertJsonPath('resourceType', 'Encounter')
        ->assertJsonPath('status', 'finished')
        ->assertJsonPath('class.code', 'EMER')
        ->assertJsonPath('class.system', 'http://terminology.hl7.org/CodeSystem/v3-ActCode')
        ->assertJsonPath('subject.reference', 'Patient/P00012345678')
        ->assertJsonPath('participant.0.individual.reference', 'Practitioner/DR987654321')
        ->assertJsonPath('participant.0.individual.display', 'Dr. Budi Setiawan, Sp.PD')
        ->assertJsonStructure([
            'identifier' => [['system', 'value']],
            'period' => ['start', 'end'],
            'serviceType' => ['coding' => [['system', 'code', 'display']]],
        ]);

    // ISO-8601 with a Z designator, per FR-17.
    expect($response->json('period.start'))->toEndWith('Z');
});

it('omits period.end for an encounter that is still open', function (): void {
    $this->encounter->update(['status' => 'arrived', 'discharge_time' => null]);

    fhirGet("/Encounter/{$this->encounter->id}")
        ->assertOk()
        ->assertJsonPath('status', 'arrived')
        ->assertJsonMissingPath('period.end');
});

it('returns 404 for an unknown encounter', function (): void {
    fhirGet('/Encounter/00000000-0000-4000-8000-000000000000')->assertStatus(404);
});

it('serialises the patient with the NIK identifier FR-16 mandates', function (): void {
    $response = fhirGet("/Patient/{$this->patient->id}");

    $response->assertOk()
        ->assertJsonPath('resourceType', 'Patient')
        ->assertJsonPath('id', 'P00012345678')
        ->assertJsonPath('gender', 'male')
        ->assertJsonPath('birthDate', '1983-05-12')
        ->assertJsonPath('identifier.0.system', 'https://fhir.kemkes.go.id/id/nik')
        ->assertJsonPath('identifier.0.value', '3315012345670001');
});

it('returns 400 when the Condition query omits the encounter parameter', function (): void {
    fhirGet('/Condition')->assertStatus(400)
        ->assertJsonPath('error.code', 'BAD_REQUEST');
});

it('returns a searchset Bundle of Condition resources with ICD-10 codings', function (): void {
    seedFormTemplates();
    $submission = makeSubmission($this->encounter);
    $submission->update(['primary_icd10_code' => 'K29.7']);

    $response = fhirGet('/Condition?encounter='.$this->encounter->id);

    $response->assertOk()
        ->assertJsonPath('resourceType', 'Bundle')
        ->assertJsonPath('type', 'searchset')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('entry.0.resource.resourceType', 'Condition')
        // AC-06.2 pins these three exactly.
        ->assertJsonPath('entry.0.resource.clinicalStatus.coding.0.code', 'active')
        ->assertJsonPath('entry.0.resource.code.coding.0.system', 'http://hl7.org/fhir/sid/icd-10')
        ->assertJsonPath('entry.0.resource.code.coding.0.code', 'K29.7')
        ->assertJsonPath('entry.0.resource.code.coding.0.display', 'Gastritis, unspecified')
        ->assertJsonPath('entry.0.resource.subject.reference', 'Patient/P00012345678')
        ->assertJsonPath('entry.0.resource.encounter.reference', 'Encounter/'.$this->encounter->id);
});

it('returns an empty Bundle when no diagnosis has been assigned yet', function (): void {
    fhirGet('/Condition?encounter='.$this->encounter->id)
        ->assertOk()
        ->assertJsonPath('resourceType', 'Bundle')
        ->assertJsonPath('total', 0)
        ->assertJsonPath('entry', []);
});

it('registers an encounter pushed from the national side, idempotently', function (): void {
    $payload = [
        'identifier' => ['value' => 'ENC-202503-0099'],
        'status' => 'arrived',
        'class' => ['code' => 'IMP'],
        'subject' => ['reference' => 'Patient/'.$this->patient->ihs_patient_id],
        'period' => ['start' => '2025-03-01T08:00:00Z'],
        'serviceType' => [['coding' => [['display' => 'Bangsal Dahlia']]]],
    ];

    $headers = ['Authorization' => 'Bearer '.$this->token];

    $this->postJson('/api/v1/fhir/Encounter', $payload, $headers)->assertStatus(201);

    // A retried registration must not create a second visit.
    $this->postJson('/api/v1/fhir/Encounter', $payload, $headers)->assertOk();

    expect(Encounter::query()->where('encounter_number', 'ENC-202503-0099')->count())->toBe(1);
});

it('refuses to register an encounter for a patient this SIMRS does not know', function (): void {
    $this->postJson('/api/v1/fhir/Encounter', [
        'identifier' => ['value' => 'ENC-202503-0100'],
        'status' => 'arrived',
        'class' => ['code' => 'IMP'],
        'subject' => ['reference' => 'Patient/UNKNOWN-IHS-ID'],
        'period' => ['start' => '2025-03-01T08:00:00Z'],
    ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(404);
});

/**
 * NFR-03 asks the FHIR read endpoints for <250 ms at a concurrency of 50. On
 * the 120 requests/minute interactive budget that is arithmetically
 * unreachable (50 callers at 250 ms each is 200 requests per second), and a
 * gateway caller has no session to key on, so without a credential-keyed
 * limiter every hospital behind one NAT shares a single bucket.
 */
it('gives the interoperability gateway its own budget, keyed by credential', function (): void {
    $encounter = makeEncounter(makePatient());

    // Far more than the 120/minute interactive allowance allows, in a burst.
    for ($i = 0; $i < 150; $i++) {
        $this->withToken(config('klinikform.fhir.token'))
            ->getJson("/api/v1/fhir/Encounter/{$encounter->id}")
            ->assertOk();
    }
});

it('still refuses a request with no credential', function (): void {
    $encounter = makeEncounter(makePatient());

    $this->getJson("/api/v1/fhir/Encounter/{$encounter->id}")->assertUnauthorized();
});

it('does not let one integrator exhaust another integrator budget', function (): void {
    // Both callers share a source IP, which is what a hospital behind one NAT
    // looks like; only the presented credential differs.
    config(['klinikform.fhir.token' => 'token-a']);

    $encounter = makeEncounter(makePatient());

    for ($i = 0; $i < 130; $i++) {
        $this->withToken('token-a')->getJson("/api/v1/fhir/Encounter/{$encounter->id}")->assertOk();
    }

    config(['klinikform.fhir.token' => 'token-b']);

    $this->withToken('token-b')
        ->getJson("/api/v1/fhir/Encounter/{$encounter->id}")
        ->assertOk();
});

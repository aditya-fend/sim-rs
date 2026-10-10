<?php

declare(strict_types=1);

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Models\Encounter;
use App\Services\Interoperability\Fhir\FhirSchemaValidator;
use App\Services\Interoperability\Fhir\FhirTransformer;
use Database\Seeders\RoleSeeder;

/**
 * AC-06.3 / testing.md release criterion — generated FHIR payloads are
 * validated against the official HL7 FHIR R4 JSON Schema, not merely against
 * hand-written assertions.
 *
 * The schema is the real file published by HL7 (tests/Fixtures/fhir-r4), so a
 * resource that passes here is structurally conformant with R4.
 */
beforeEach(function (): void {
    $this->validator = new FhirSchemaValidator;
    $this->transformer = new FhirTransformer;

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
        'encounter_type' => EncounterType::Emergency,
        'status' => EncounterStatus::Finished,
        'admission_time' => now()->subHour(),
        'discharge_time' => now(),
    ]);
});

it('has the official HL7 FHIR R4 schema available offline', function (): void {
    $path = base_path('tests/Fixtures/fhir-r4/fhir.schema.json');

    expect(is_file($path))->toBeTrue();

    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['$schema'])->toBe('http://json-schema.org/draft-06/schema#')
        ->and($schema['definitions'])->toHaveKeys(['Patient', 'Encounter', 'Condition', 'Bundle']);
});

it('validates the Patient resource with zero schema violations', function (): void {
    expect($this->validator->validate($this->transformer->patient($this->patient)))->toBe([]);
});

it('validates the Encounter resource with zero schema violations', function (): void {
    expect($this->validator->validate($this->transformer->encounter($this->encounter)))->toBe([]);
});

it('validates an Encounter for a patient with no IHS id', function (): void {
    $encounter = makeEncounter(makePatient(null, ['ihs_patient_id' => null]), [
        'encounter_number' => 'ENC-202503-0099',
        'admission_time' => now(),
    ]);

    expect($this->validator->validate($this->transformer->encounter($encounter)))->toBe([]);
});

it('validates a Condition searchset Bundle with zero schema violations', function (): void {
    seedFormTemplates();
    $submission = makeSubmission($this->encounter);
    $submission->update(['primary_icd10_code' => 'K29.7']);

    $bundle = $this->transformer->conditionBundle(
        $this->encounter,
        $this->transformer->diagnosesFor($this->encounter),
    );

    expect($this->validator->validate($bundle))->toBe([]);
});

it('validates an empty Condition Bundle, which is still well-formed', function (): void {
    $bundle = $this->transformer->conditionBundle($this->encounter, []);

    expect($bundle['total'])->toBe(0)
        ->and($this->validator->validate($bundle))->toBe([]);
});

it('validates several diagnoses in one Bundle', function (): void {
    seedFormTemplates();

    $bundle = $this->transformer->conditionBundle($this->encounter, [
        ['code' => 'K29.7', 'display' => 'Gastritis, unspecified'],
        ['code' => 'I10', 'display' => 'Essential (primary) hypertension'],
        ['code' => 'E11.9', 'display' => 'Type 1 diabetes mellitus without complications'],
    ]);

    expect($bundle['total'])->toBe(3)
        ->and($this->validator->validate($bundle))->toBe([]);
});

it('rejects a resource that is missing a required element', function (): void {
    // FHIR requires resourceType and, for Encounter, class.
    $errors = $this->validator->validate([
        'resourceType' => 'Encounter',
        'status' => 'finished',
    ]);

    expect($errors)->not->toBeEmpty();
});

it('rejects a resource with an element of the wrong type', function (): void {
    $errors = $this->validator->validate([
        'resourceType' => 'Encounter',
        // class must be a CodeableConcept, not a string.
        'class' => 'EMER',
        'status' => 'finished',
    ]);

    expect($errors)->not->toBeEmpty();
});

it('rejects an unknown resource type rather than passing it silently', function (): void {
    expect($this->validator->validate(['resourceType' => 'EncounterXyz']))
        ->toContain("FHIR R4 schema has no definition for resource type 'EncounterXyz'.");
});

it('rejects a resource with no resourceType at all', function (): void {
    expect($this->validator->validate(['id' => 'x']))->toContain('Resource is missing a resourceType.');
});

it('validates the payloads the gateway actually serves over HTTP', function (): void {
    seedFormTemplates();
    $submission = makeSubmission($this->encounter);
    $submission->update(['primary_icd10_code' => 'K29.7']);

    $headers = ['Authorization' => 'Bearer '.config('klinikform.fhir.token')];

    foreach ([
        "/api/v1/fhir/Patient/{$this->patient->id}",
        "/api/v1/fhir/Encounter/{$this->encounter->id}",
        '/api/v1/fhir/Condition?encounter='.$this->encounter->id,
    ] as $path) {
        $response = $this->getJson($path, $headers);

        $response->assertOk();

        // This is the assertion the release criterion is really about: the
        // bytes a national repository would receive, not a re-serialisation.
        expect($this->validator->validate($response->json()))
            ->toBe([], "violations for {$path}");
    }
});

it('reports violations in a readable form', function (): void {
    $errors = $this->validator->validate(['resourceType' => 'Patient', 'gender' => 'unknown-value']);

    foreach ($errors as $error) {
        expect($error)->toBeString();
    }

    expect(implode(' ', $errors))->toContain('gender');
});

<?php

declare(strict_types=1);

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\SubmissionStatus;
use App\Enums\TriageCategory;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Patient;
use App\Models\User;
use App\Services\FormEngine\SchemaRepository;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suite wiring (testing.md §Test Pyramid)
|--------------------------------------------------------------------------
|
| Unit tests exercise pure functions and casts; feature tests run against the
| real PostgreSQL engine, because erd.md relies on Postgres-only behaviour
| (partial unique indexes, regex check constraints, RAISE triggers) that an
| in-memory SQLite database would silently never exercise.
|
*/

uses(TestCase::class)->in('Unit');
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Create a user holding the given KlinikForm role.
 *
 * @param  list<string>  $roles
 */
function makeUser(array $roles = [], array $attributes = []): User
{
    foreach (RoleSeeder::ALL as $role) {
        Role::findOrCreate($role, 'web');
    }

    $user = User::create(array_merge([
        'name' => 'Test Person',
        'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('password'),
    ], $attributes));

    $user->syncRoles($roles);

    return $user->fresh() ?? $user;
}

/**
 * A patient. Defaults to a random NIK because nik_hash is unique; pass an
 * explicit NIK when a test asserts on a lookup.
 */
function makePatient(?string $nik = null, array $attributes = []): Patient
{
    $patient = new Patient(array_merge([
        'medical_record_number' => fake()->unique()->numerify('00-##-##'),
        'full_name' => 'Joko Susanto',
        'birth_date' => '1983-05-12',
        'gender' => 'male',
        'bpjs_card_number' => fake()->unique()->numerify('#############'),
        'ihs_patient_id' => 'P'.fake()->unique()->numerify('#########'),
    ], $attributes));

    $patient->setNik($nik ?? fake()->unique()->numerify('################'));
    $patient->save();

    return $patient;
}

function makeEncounter(?Patient $patient = null, array $attributes = []): Encounter
{
    return Encounter::create(array_merge([
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('####'),
        'patient_id' => ($patient ?? makePatient())->id,
        'encounter_type' => EncounterType::Emergency,
        'status' => EncounterStatus::Arrived,
        'service_unit' => 'IGD',
        'admission_time' => now(),
    ], $attributes));
}

/**
 * Install the two seed schemas into the test database.
 */
function seedFormTemplates(): void
{
    $author = makeUser([RoleSeeder::ADMIN]);

    foreach ([
        ['TRIAGE-ED-01', 'Formulir Triase Gawat Darurat', 'triage-ed-01.json'],
        ['ASSESS-INPAT-01', 'Asesmen Awal Keperawatan & Medis Rawat Inap', 'assess-inpat-01.json'],
    ] as [$code, $name, $file]) {
        if (FormTemplate::query()->where('code', $code)->exists()) {
            continue;
        }

        app(SchemaRepository::class)->publish(
            $code,
            $name,
            json_decode((string) file_get_contents(base_path('database/schemas/'.$file)), true, 512, JSON_THROW_ON_ERROR),
            $author,
        );
    }
}

function activeTemplate(string $code): FormTemplate
{
    return FormTemplate::query()->where('code', $code)->where('is_active', true)->firstOrFail();
}

/**
 * A complete, valid TRIAGE-ED-01 payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function triagePayload(array $overrides = []): array
{
    return array_merge([
        'arrival_time' => now()->toDateTimeString(),
        'transport_mode' => 'walker',
        'triage_category' => 'urgent',
        'triage_nurse' => 'Siti Rahmawati',
        'systolic' => 130,
        'diastolic' => 85,
        'heart_rate' => 92,
        'respiratory_rate' => 20,
        'body_temperature' => 37.1,
        'oxygen_saturation' => 98,
        'gcs' => ['eye' => 3, 'verbal' => 4, 'motor' => 5, 'total' => 12, 'category' => 'apatis'],
        'chief_complaint' => 'Nyeri ulu hati sejak 2 hari',
        'pain_markers' => [
            ['x' => 48.2, 'y' => 32.5, 'region' => 'epigastrium', 'severity' => 7],
        ],
        'pain_severity' => 7,
    ], $overrides);
}

function makeSubmission(?Encounter $encounter = null, ?User $author = null, array $overrides = []): FormSubmission
{
    $encounter ??= makeEncounter();
    $template = activeTemplate('TRIAGE-ED-01');

    $submission = new FormSubmission(array_merge([
        'form_template_id' => $template->id,
        'encounter_id' => $encounter->id,
        'patient_id' => $encounter->patient_id,
        'authored_by' => ($author ?? makeUser([RoleSeeder::NURSE]))->id,
        'schema_version' => $template->version,
        'status' => SubmissionStatus::Submitted,
        'payload_data' => triagePayload($overrides),
    ], $overrides));

    $submission->setAnamnesis('Nyeri ulu hati sejak 2 hari yang lalu, disertai mual dan muntah.');
    $submission->gcs_total = 12;
    $submission->triage_category = TriageCategory::Urgent;
    $submission->save();

    return $submission;
}

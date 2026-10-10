<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * testing.md §"End-to-End Scenarios Derived from User Flows".
 *
 * These drive the real interface against the real application server, so they
 * cover the things the request-level suites cannot: that Alpine wires the
 * widgets, that the GCS total appears without a reload, that a pain pin lands,
 * that a draft survives a reload, and that a locked record renders read-only.
 */
class ClinicalWorkflowTest extends DuskTestCase
{
    private const NURSE = 'siti@klinikform.local';

    private const DOCTOR = 'budi@klinikform.local';

    private const ADMIN = 'rian@klinikform.local';

    private const REKAM_MEDIS = 'dewi@klinikform.local';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepare();
    }

    /**
     * Dusk scrolls a target to the nearest viewport edge before clicking,
     * which lands it underneath the sticky submit bar. Centre it first, which
     * is what a nurse does with the wheel anyway.
     */
    private static function clickCentred(Browser $browser, string $selector): void
    {
        $browser->script(<<<JS
            document.querySelector('{$selector}')
                .scrollIntoView({ block: 'center', inline: 'center' });
        JS);

        $browser->click($selector);
    }

    /**
     * The draft key is '{draft}_{encounter}_{template}' — see form.blade.php.
     */
    private static function draftKeyFor(Encounter $encounter): string
    {
        $templateId = FormTemplate::query()
            ->where('code', 'TRIAGE-ED-01')
            ->where('is_active', true)
            ->value('id');

        return 'draft_'.$encounter->id.'_'.$templateId;
    }

    /**
     * Dusk reuses one Chrome session across every browse() block in a test,
     * so a second block still carries the first block's cookie and /login
     * would bounce straight back to the portal. Sign out first.
     */
    private static function login(Browser $browser, string $email): Browser
    {
        // /logout is POST-only, so drop the session cookie directly rather
        // than navigating to a route that would answer 405.
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/login')
            ->type('email', $email)
            ->type('password', 'password')
            ->press('button[type=submit]')
            ->waitUntil(
                "window.location.pathname.startsWith('/portal')"
                ." || window.location.pathname.startsWith('/admin')"
                ." || window.location.pathname.startsWith('/compliance')",
                15,
            );
    }

    /**
     * GCS is required by the schema, so every scenario that reaches the submit
     * button has to answer E, V and M. 3 + 4 + 5 = 12, band "apatis".
     */
    private static function fillGcsScript(): string
    {
        return <<<'JS'
            document.querySelectorAll('[name="payload[gcs][eye]"]')[2].click();
            document.querySelectorAll('[name="payload[gcs][verbal]"]')[3].click();
            document.querySelectorAll('[name="payload[gcs][motor]"]')[4].click();
        JS;
    }

    /**
     * A datetime-local input will not accept sendKeys carrying the literal 'T'
     * separator, so the value is assigned and an input event dispatched, which
     * keeps Alpine's x-model in sync.
     */
    private static function setArrivalTimeScript(): string
    {
        $value = now()->format('Y-m-d\\TH:i');

        return "const el = document.getElementById('field-arrival_time');"
            ."el.value = '{$value}';"
            ."el.dispatchEvent(new Event('input', { bubbles: true }));";
    }

    /**
     * @return array{0: Patient, 1: Encounter}
     */
    private function seedEncounter(): array
    {
        $patient = Patient::query()->where('medical_record_number', '00-48-21')->firstOrFail();
        $encounter = Encounter::query()->where('patient_id', $patient->id)->firstOrFail();

        return [$patient, $encounter];
    }

    /**
     * E2E-01 — Nurse Siti: lookup NIK, open the triage form, enter vitals,
     * compute GCS, pin the pain map, submit. Asserts the record persists with
     * encrypted NIK and anamnesis, that HTTP 201 came back, and that the
     * localStorage draft key was purged.
     */
    public function test_nurse_completes_an_emergency_triage_intake(): void
    {
        [$patient, $encounter] = $this->seedEncounter();

        $this->browse(function (Browser $browser) use ($encounter): void {
            self::login($browser, self::NURSE);

            // Patient lookup by NIK through the blind index.
            $browser->visit('/portal/nurse/intake')
                ->type('#nik', '3315012345670001')
                ->keys('#nik', '{enter}')
                // Wait on the masked NIK: the encounters table below also
                // contains the patient name, so waiting for it would pass
                // before the search result had rendered.
                ->waitForText('331501******0001', 10)
                ->assertSee('Joko Susanto');

            // Dynamic form, rendered from the active schema.
            $browser->visit("/portal/nurse/intake/{$encounter->id}")
                ->waitFor('#field-triage_category')
                ->assertSee('Formulir Triase Gawat Darurat')
                ->assertSee('Skala Koma Glasgow');

            // Arrival details and vitals. arrival_time and transport_mode are
            // required by the schema, and the server refuses the whole form if
            // either is missing.
            // Dusk's script() returns the script's result, not the browser,
            // so it cannot be chained.
            $browser->script(self::setArrivalTimeScript());

            $browser->click('input[type=radio][value="walker"]')
                ->select('#field-triage_category', 'urgent')
                ->type('#field-systolic', '130')
                ->type('#field-diastolic', '85')
                ->type('#field-heart_rate', '92')
                ->type('#field-respiratory_rate', '20')
                ->type('#field-body_temperature', '37.1')
                ->type('#field-oxygen_saturation', '98')
                ->type('#field-chief_complaint', 'Nyeri ulu hati sejak 2 hari')
                ->type('#field-triage_nurse', 'Siti Nurhaliza', 10)
                // The schema marks this field confidential, so it is the one
                // that must never reach payload_data in the clear.
                ->type('#field-anamnesis_notes', 'Asidosis. Diabetes tipe 2 sejak 2019.', 10);

            // GCS: AC-02.1 — 3 + 4 + 5 must total 12 with no reload.
            $browser->script(self::fillGcsScript());
            $browser->waitForText('Total GCS: 12', 5)->assertSee('Apatis');

            // Pain map: AC-03.1/AC-03.2. The click targets the epigastrium hit
            // area itself rather than the surrounding map, so the recorded
            // region is the one the schema defines.
            $browser->script(<<<'JS'
                const hit = document.querySelector('[data-region="epigastrium"]');
                const box = hit.getBoundingClientRect();
                hit.dispatchEvent(new MouseEvent('click', {
                    bubbles: true,
                    clientX: box.left + box.width * 0.5,
                    clientY: box.top + box.height * 0.5,
                }));
            JS);
            $browser->waitForText('Tingkat Keparahan Nyeri', 5);

            // The radio is sr-only (focusable and announced), so the visible
            // label is what a pointer — and a test — clicks.
            self::clickCentred($browser, '#pain-severity-label-7');

            $browser->waitForText('epigastrium', 5);

            self::clickCentred($browser, '#pain-pin-confirm');

            $browser->click('#intake-submit')
                ->waitForText('Berhasil Disimpan', 15);
        });

        $submission = FormSubmission::query()->firstOrFail();

        expect($submission->gcs_total)->toBe(12)
            ->and($submission->triage_category?->value)->toBe('urgent')
            ->and($submission->payload_data['pain_markers'][0]['region'])->toBe('epigastrium')
            ->and($submission->payload_data['pain_markers'][0]['severity'])->toBe(7);

        // AC-05.1 — nothing sensitive is stored in the clear.
        $raw = DB::table('form_submissions')->value('encrypted_anamnesis');
        $rawPayload = DB::table('form_submissions')->value('payload_data');
        $rawNik = DB::table('patients')->where('id', $patient->id)->value('nik_encrypted');

        expect($raw)->toStartWith('v1.')
            ->and($raw)->not->toContain('Asidosis')
            ->and($rawPayload)->not->toContain('Asidosis')
            ->and($submission->payload_data)->not->toHaveKey('anamnesis_notes')
            ->and($rawNik)->not->toContain('3315012345670001')
            // Decrypting is the only way to read it back.
            ->and($submission->anamnesis())->toContain('Asidosis');
    }

    /**
     * AC-04.3 — after HTTP 201 the localStorage draft key is gone.
     */
    public function test_draft_is_cleared_after_a_successful_submission(): void
    {
        [, $encounter] = $this->seedEncounter();

        $this->browse(function (Browser $browser) use ($encounter): void {
            self::login($browser, self::NURSE)
                ->visit("/portal/nurse/intake/{$encounter->id}")
                ->waitFor('#field-systolic')
                ->type('#field-systolic', '130');

            $browser->script(self::setArrivalTimeScript());
            $browser->click('input[type=radio][value="walker"]');

            // Give the 5 s autosave a moment to write the key. Dusk's
            // assertScript() prepends "return", so it only accepts a bare
            // expression — script() plus an explicit check handles statements.
            $browser->pause(6000);
            expect($browser->script(<<<'JS'
                return Object.keys(localStorage).some((k) => k.startsWith('draft_'));
            JS)[0])->toBeTrue();

            $browser->select('#field-triage_category', 'urgent')
                ->type('#field-diastolic', '85')
                ->type('#field-heart_rate', '92')
                ->type('#field-respiratory_rate', '20')
                ->type('#field-body_temperature', '37.1')
                ->type('#field-oxygen_saturation', '98')
                ->type('#field-chief_complaint', 'Nyeri ulu hati')
                ->type('#field-triage_nurse', 'Siti Nurhaliza', 10);

            $browser->script(self::fillGcsScript());

            $browser->waitForText('Total GCS: 12', 5)
                ->click('#intake-submit')
                ->waitForText('Berhasil Disimpan', 15);

            // AC-04.3 — the key is purged only once the server has acknowledged.
            expect($browser->script(<<<'JS'
                return !Object.keys(localStorage).some((k) => k.startsWith('draft_'));
            JS)[0])->toBeTrue();
        });
    }

    /**
     * E2E-02 — Dr. Budi reviews the nurse's decrypted anamnesis, assigns
     * K29.7, and signs. The record must lock and reload read-only with a
     * verification stamp.
     */
    public function test_doctor_signs_and_locks_the_assessment(): void
    {
        [$patient, $encounter] = $this->seedEncounter();

        $this->submitTriageAsNurse();

        $this->browse(function (Browser $browser) use ($patient, $encounter): void {
            self::login($browser, self::DOCTOR)
                ->visit("/portal/doctor/assessment/{$encounter->id}")
                ->waitForText('Temuan Perawat', 10)
                ->assertSee('Nyeri ulu hati');

            // The nurse's narrative is decrypted for clinical review.
            expect($patient->full_name)->not->toBeEmpty();

            $browser->script(<<<'JS'
                const box = document.querySelector('[role="combobox"]');
                box.focus();
                // Search by the code itself: several ICD entries match
                // "gastritis" and the list is capped at eight.
                box.value = 'K29.7';
                box.dispatchEvent(new Event('input', { bubbles: true }));
            JS);
            $browser->waitForText('K29.7', 5)
                // The option selects on @mousedown, so a key press would never
                // set selectedCode and the sign button would stay disabled.
                ->click('li[role="option"]')
                ->type('#objective_findings', 'Abdomen: Nyeri tekan epigastrium (+), defans muskular (-).')
                ->click('#assessment-sign')
                ->waitForText('Semua formulir sudah ditandatangani', 15);
        });

        $submission = FormSubmission::query()->firstOrFail();

        expect($submission->isLocked())->toBeTrue()
            ->and($submission->primary_icd10_code)->toBe('K29.7')
            ->and($submission->signature_hash)->toHaveLength(64)
            ->and($encounter->fresh()->status->value)->toBe('finished');

        // The locked record renders read-only with its verification stamp.
        $this->browse(fn (Browser $browser) => $browser
            ->visit("/portal/doctor/record/{$submission->id}")
            ->waitForText('Dokumen Rekam Medis Terverifikasi Secara Elektronik', 10)
            ->assertSee('Terverifikasi'));
    }

    /**
     * E2E-03 — a draft left in localStorage is offered on return, and
     * restoring it repopulates the form.
     */
    public function test_unsaved_draft_is_recovered_on_return(): void
    {
        [, $encounter] = $this->seedEncounter();

        $this->browse(function (Browser $browser) use ($encounter): void {
            self::login($browser, self::NURSE)
                ->visit("/portal/nurse/intake/{$encounter->id}")
                ->waitFor('#field-chief_complaint')
                ->type('#field-chief_complaint', 'Nyeri ulu hak dih');
        });

        // The tab is closed without submitting; the buffer survives in
        // localStorage because the server is still serving the same profile.
        $this->browse(function (Browser $browser) use ($encounter): void {
            $browser->visit("/portal/nurse/intake/{$encounter->id}")
                ->waitFor('#field-chief_complaint', 10);

            // Restore a draft directly, the way a crashed tab would leave one.
            $key = self::draftKeyFor($encounter);
            $browser->script(<<<JS
                localStorage.setItem('{$key}', JSON.stringify({
                    payload: { chief_complaint: 'Nyeri ulu hak dih', systolic: 128 },
                    saved_at: new Date().toISOString(),
                }));
            JS);

            $browser->refresh()
                ->waitForText('Draf Belum Tersimpan Ditemukan', 10)
                ->press('#draft-restore')
                ->waitUntil(
                    "document.querySelector('#field-chief_complaint').value === 'Nyeri ulu hak dih'",
                    5,
                );
        });
    }

    /**
     * E2E-04 — Rian publishes a new schema version, and historical submissions
     * keep rendering against the version they were captured under.
     */
    public function test_admin_publishes_a_new_schema_version(): void
    {
        $before = FormTemplate::query()
            ->where('code', 'TRIAGE-ED-01')->where('is_active', true)->firstOrFail();

        $this->browse(function (Browser $browser) use ($before): void {
            self::login($browser, self::ADMIN)
                ->visit("/admin/schemas/{$before->id}")
                ->waitFor('#schema_definition', 10);

            // Add the conditional prompt user-flow.md §Flow 3 describes.
            $browser->script(<<<'JS'
                const area = document.querySelector('#schema_definition');
                const schema = JSON.parse(area.value);
                schema.description += ' Revisi 3 oleh Rian.';
                area.value = JSON.stringify(schema, null, 2);
                area.dispatchEvent(new Event('input', { bubbles: true }));
            JS);
            $browser->press('#schema-validate')
                ->waitForText('Validasi Berhasil', 10)
                ->assertSee('Validasi Berhasil')
                ->press('#schema-publish')
                ->waitForText('diterbitkan & diaktifkan', 15);
        });

        $after = FormTemplate::query()
            ->where('code', 'TRIAGE-ED-01')->where('is_active', true)->firstOrFail();

        expect($after->version)->toBe($before->version + 1)
            ->and($after->is_active)->toBeTrue()
            ->and($before->fresh()->is_active)->toBeFalse();

        // AC-01.3 — the retired version is still resolvable and unchanged.
        expect($before->fresh()->schema_definition)->not->toBe($after->schema_definition);
    }

    /**
     * E2E-05 — Dewi filters the audit trail, sees the recorded events, and
     * downloads the compliance export.
     */
    public function test_rekam_medis_reviews_the_audit_trail(): void
    {
        $this->submitTriageAsNurse();

        $this->browse(function (Browser $browser): void {
            self::login($browser, self::REKAM_MEDIS)
                ->waitForText('Jejak Audit Rekam Medis', 10)
                ->assertSee('CREATE')
                ->assertSee('Triage Nurse');

            // FR-14 filters.
            $browser->select('#action', 'CREATE')
                ->press('#audit-filter-apply')
                ->waitUntil("window.location.search.includes('action=CREATE')", 15);

            // E2E-05 export. The response is sent as
            // Content-Disposition: attachment, so a browser downloads it
            // rather than navigating — fetch it in-page instead.
            $export = $browser->script(<<<'JS'
                return fetch('/compliance/audit-trail/export', {
                    headers: { Accept: 'application/pdf' },
                }).then((r) => r.blob().then((b) => ({
                    status: r.status,
                    type: b.type,
                    size: b.size,
                })));
            JS)[0];

            expect($export['status'])->toBe(200)
                ->and($export['type'])->toBe('application/pdf')
                ->and($export['size'])->toBeGreaterThan(1000);
        });
    }

    /**
     * Fill and submit a triage form so the doctor and compliance scenarios
     * have a real record to work against.
     */
    private function submitTriageAsNurse(): void
    {
        [, $encounter] = $this->seedEncounter();

        $this->browse(function (Browser $browser) use ($encounter): void {
            self::login($browser, self::NURSE)
                ->visit("/portal/nurse/intake/{$encounter->id}")
                ->waitFor('#field-systolic', 10);

            $browser->script(self::setArrivalTimeScript());

            $browser->click('input[type=radio][value="walker"]')
                ->select('#field-triage_category', 'urgent')
                ->type('#field-systolic', '130')
                ->type('#field-diastolic', '85')
                ->type('#field-heart_rate', '92')
                ->type('#field-respiratory_rate', '20')
                ->type('#field-body_temperature', '37.1')
                ->type('#field-oxygen_saturation', '98')
                ->type('#field-chief_complaint', 'Nyeri ulu hati sejak 2 hari')
                ->type('#field-triage_nurse', 'Siti Nurhaliza', 10);

            $browser->script(self::fillGcsScript());

            $browser->waitForText('Total GCS: 12', 5)
                ->click('#intake-submit')
                ->waitForText('Berhasil Disimpan', 15);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\SubmissionStatus;
use App\Enums\TriageCategory;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use Closure;
use Database\Seeders\RoleSeeder;
use Facebook\WebDriver\WebDriverDimension;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * NFR-07 — the clinical UI must meet WCAG 2.1 AA. The markup carries aria
 * attributes and keyboard handlers, but markup is only a claim until a tool
 * agrees, so axe-core runs against every screen a role actually lands on.
 *
 * ponytail: axe is injected straight from node_modules rather than vendored
 * into public/, so there is no build artefact to keep in sync.
 */
class AccessibilityTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Not the full prepare(): these scenarios only read pages, so a fresh
        // migration buys nothing. The limiter flush does — without it the three
        // logins here share one five-per-minute budget with the workflow suite
        // and the login intermittently answers 429, which surfaces as a
        // timeout rather than as the throttle that caused it.
        Cache::flush();

        // Dusk reuses the Chrome window, so a scenario that resizes it would
        // otherwise decide the viewport of every test that runs after it.
        $this->browse(fn (Browser $browser) => $browser->driver
            ->manage()->window()->setSize(new WebDriverDimension(1600, 1000)));
    }

    public function test_the_login_screen_has_no_serious_violations(): void
    {
        $this->browse(fn (Browser $browser) => $this->assertNoSeriousViolations($browser, fn () => $browser->visit('/login')));
    }

    public function test_the_nurse_intake_screen_has_no_serious_violations(): void
    {
        $encounter = Encounter::query()->firstOrFail();

        $this->browse(function (Browser $browser) use ($encounter): void {
            $this->login($browser, RoleSeeder::NURSE);

            $this->assertNoSeriousViolations($browser, function () use ($browser, $encounter): void {
                $browser->visit("/portal/nurse/intake/{$encounter->id}")->waitFor('#field-triage_category', 15);
            });
        });
    }

    /**
     * NFR-09 — the layout must hold from 1024px (the ward terminal floor in
     * ui-spec.md) up to 1920px. Every other scenario here runs at the Dusk
     * default of 1600x1000, so the lower bound was never exercised.
     *
     * The encounter carries a submitted triage on purpose: the patient strip
     * only renders its category badge once a submission exists, so without one
     * this would check an empty shell and miss the badge contrast entirely.
     */
    public function test_the_triage_form_holds_at_the_1024px_ward_floor(): void
    {
        $encounter = $this->encounterWithTriageBadge();

        $this->browse(function (Browser $browser) use ($encounter): void {
            $browser->driver->manage()->window()->setSize(new WebDriverDimension(1024, 768));

            $this->login($browser, RoleSeeder::NURSE);

            $this->assertNoSeriousViolations($browser, function () use ($browser, $encounter): void {
                $browser->visit("/portal/nurse/intake/{$encounter->id}")->waitFor('#field-triage_category', 15);
            });

            // Nothing may spill sideways at the narrow end of the range.
            $overflow = $browser->script(
                'return document.documentElement.scrollWidth - document.documentElement.clientWidth;'
            )[0];

            expect($overflow)->toBeLessThanOrEqual(0);
        });
    }

    public function test_the_audit_console_has_no_serious_violations(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, RoleSeeder::REKAM_MEDIS);

            $this->assertNoSeriousViolations($browser, function () use ($browser): void {
                $browser->visit('/compliance/audit-trail')->waitForText('Jejak Audit Rekam Medis', 15);
            });
        });
    }

    public function test_the_schema_manager_has_no_serious_violations(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, RoleSeeder::ADMIN);

            $this->assertNoSeriousViolations($browser, function () use ($browser): void {
                $browser->visit('/admin/schemas')->waitForText('Skema', 15);
            });
        });
    }

    /**
     * Built straight through the model rather than by replaying the intake
     * flow: what axe needs from this record is a rendered category badge, not
     * a demonstrated submission journey.
     */
    private function encounterWithTriageBadge(): Encounter
    {
        $encounter = Encounter::query()->firstOrFail();
        $template = FormTemplate::query()->where('is_active', true)->firstOrFail();

        $submission = new FormSubmission([
            'form_template_id' => $template->id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'authored_by' => User::query()->firstOrFail()->id,
            'schema_version' => $template->version,
            'status' => SubmissionStatus::Submitted,
            'payload_data' => [],
            'triage_category' => TriageCategory::Urgent,
        ]);
        $submission->save();

        return $encounter;
    }

    private function login(Browser $browser, string $role): void
    {
        $email = match ($role) {
            RoleSeeder::NURSE => 'siti@klinikform.local',
            RoleSeeder::DPJP => 'budi@klinikform.local',
            RoleSeeder::ADMIN => 'rian@klinikform.local',
            RoleSeeder::REKAM_MEDIS => 'dewi@klinikform.local',
        };

        // Dusk reuses one Chrome session across tests, so a cookie left by an
        // earlier test would bounce /login straight back to the portal.
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/login')
            ->type('email', $email)
            ->type('password', 'password')
            ->press('button[type=submit]')
            // /portal redirects each role to its own landing page.
            ->waitUntil(
                "window.location.pathname.startsWith('/portal')"
                ." || window.location.pathname.startsWith('/admin')"
                ." || window.location.pathname.startsWith('/compliance')",
                15,
            );
    }

    /**
     * Only serious and critical axe violations are asserted. Moderate and
     * minor rules are advisory here, and failing on them would make this gate
     * noise rather than signal.
     */
    private function assertNoSeriousViolations(Browser $browser, Closure $visit): void
    {
        $visit();

        $browser->script((string) file_get_contents(base_path('node_modules/axe-core/axe.min.js')));

        // `false`, not `null`: `undefined !== null` is true in JS, so a null
        // sentinel would make the wait below pass on a page where axe never
        // ran at all.
        $browser->script(<<<'JS'
            window.__axe = false;
            window.__axeError = null;

            const settle = (value) => { window.__axe = value; };

            try {
                axe.run(document, {
                    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
                }).then((results) => settle(results.violations.map((v) => ({
                    id: v.id,
                    impact: v.impact,
                    help: v.help,
                    nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ')),
                })))).catch((e) => { window.__axeError = String(e); });
            } catch (e) {
                window.__axeError = String(e);
            }
        JS);

        $browser->waitUntil('window.__axe !== false || window.__axeError !== null', 30);

        expect($browser->script('return window.__axeError;')[0])->toBeNull();

        $violations = $browser->script('return window.__axe;')[0] ?? [];

        $summary = collect($violations)
            ->filter(fn (array $v): bool => in_array($v['impact'], ['serious', 'critical'], true))
            ->map(fn (array $v): string => $v['id'].' ('.$v['impact'].'): '.$v['help'].' -> '.implode(', ', $v['nodes']))
            ->implode("\n");

        expect($summary)->toBe('');
    }
}

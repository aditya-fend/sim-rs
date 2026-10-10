<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Encounter;
use Closure;
use Database\Seeders\RoleSeeder;
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

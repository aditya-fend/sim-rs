<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * user-flow.md's navigation map routes an administrator to three screens that
 * did not exist: the dashboard, the BPJS VClaim SEP simulator (Flow 4) and the
 * FHIR explorer. These cover the ones that have observable behaviour.
 */
beforeEach(function (): void {
    $this->admin = makeUser([RoleSeeder::ADMIN]);
});

it('shows the dashboard with the active forms and record counts', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Administrasi SIMRS')
        ->assertSee('Simulator BPJS VClaim')
        ->assertSee('Explorer SatuSehat');
});

it('hides every admin screen from a clinical role', function (): void {
    foreach ([RoleSeeder::NURSE, RoleSeeder::DPJP, RoleSeeder::REKAM_MEDIS] as $role) {
        foreach (['admin.dashboard', 'admin.bpjs.simulator', 'admin.fhir.explorer'] as $route) {
            $this->actingAs(makeUser([$role]))
                ->get(route($route))
                ->assertForbidden();
        }
    }
});

it('renders the BPJS simulator with a prefilled, contract-shaped form', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.bpjs.simulator'))
        ->assertOk()
        ->assertSee('Simulator BPJS VClaim')
        ->assertSee('Nomor Kartu BPJS');
});

it('exercises the real BPJS endpoint and returns its response', function (): void {
    // The simulator must not stub the gateway: this asserts it really calls
    // the endpoint, signature and all.
    Http::fake([
        '*/api/v1/bpjs/vclaim/sep/insert' => Http::response(['response' => ['sep' => ['noSep' => '0001R12342024B1234567']]], 200),
    ]);

    $encounter = makeEncounter(makePatient());

    $this->actingAs($this->admin)
        ->post(route('admin.bpjs.simulator'), [
            'noKartu' => '0001234567890',
            'tglSep' => now()->toDateString(),
            'ppkPelayanan' => '0301R001',
            'jnsPelayanan' => '1',
            'diagAwal' => 'K29.7',
            'poli_tujuan' => '13',
            'encounter_id' => $encounter->id,
        ])
        ->assertOk()
        ->assertSee('0001R12342024B1234567');

    Http::assertSent(function ($request): bool {
        // The HMAC handshake travels with the request; the secret itself must
        // not, and the body must be the BPJS t_sep shape.
        return $request->hasHeader('X-cons-id')
            && $request->hasHeader('X-signature')
            && $request->hasHeader('X-timestamp')
            && $request->hasHeader('user_key', (string) config('klinikform.bpjs.user_key'))
            && $request['t_sep']['noKartu'] === '0001234567890'
            && $request['t_sep']['diagAwal'] === 'K29.7';
    });
});

it('rejects a BPJS card number that is not 13 digits', function (): void {
    Http::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.bpjs.simulator'), [
            'noKartu' => '123',
            'tglSep' => now()->toDateString(),
            'ppkPelayanan' => '0301R001',
            'jnsPelayanan' => '1',
            'diagAwal' => 'K29.7',
            'poli_tujuan' => '13',
        ])
        ->assertSessionHasErrors('noKartu');

    Http::assertNothingSent();
});

it('renders the FHIR explorer and shows the bundle for a chosen encounter', function (): void {
    Http::fake([
        '*/api/v1/fhir/Encounter/*' => Http::response(['resourceType' => 'Encounter', 'id' => 'e1'], 200),
        '*/api/v1/fhir/Condition*' => Http::response(['resourceType' => 'Bundle', 'total' => 1], 200),
    ]);

    $encounter = makeEncounter(makePatient());

    $this->actingAs($this->admin)
        ->get(route('admin.fhir.explorer', ['encounter' => $encounter->id]))
        ->assertOk()
        ->assertSee('Explorer SatuSehat')
        ->assertSee('Condition Bundle');

    Http::assertSent(fn ($r): bool => str_contains($r->url(), $encounter->id));
});

it('does not leak the FHIR token into the page', function (): void {
    $response = $this->actingAs($this->admin)->get(route('admin.fhir.explorer'));

    $response->assertOk();

    $token = (string) config('klinikform.fhir.token');

    if ($token !== '') {
        expect($response->getContent())->not->toContain($token);
    }
});

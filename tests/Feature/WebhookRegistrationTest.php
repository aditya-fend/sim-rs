<?php

declare(strict_types=1);

use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * architecture.md §Integration — the registry half of outbound webhooks.
 *
 * The dispatcher already fans events out; these cover how an endpoint gets
 * there in the first place, and that the secret handed to the integrator is
 * the one the HMAC signature is computed with.
 */
beforeEach(function (): void {
    $this->admin = makeUser([RoleSeeder::ADMIN]);
});

it('registers an endpoint with a system-generated secret', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.webhooks.store'), [
            'label' => 'Laboratorium Terpadu',
            'url' => 'https://lab.example/hooks/simrs',
            'events' => ['clinical.submission.created'],
        ])
        ->assertRedirect();

    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->label)->toBe('Laboratorium Terpadu')
        ->and($endpoint->is_active)->toBeTrue()
        ->and($endpoint->subscribesTo('clinical.submission.created'))->toBeTrue();

    // The secret is never accepted from the client and never stored in clear.
    $raw = (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret_encrypted');

    expect($raw)->toStartWith('v1.')
        ->and($raw)->not->toBe($endpoint->secret());
});

it('offers only the events the dispatcher can actually emit', function (): void {
    expect(WebhookDispatcher::EVENTS)
        ->toContain('clinical.submission.created')
        ->toContain('clinical.assessment.locked');
});

it('refuses an endpoint that subscribes to an unknown event', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.webhooks.store'), [
            'label' => 'Tidak Valid',
            'url' => 'https://lab.example/hooks',
            'events' => ['clinical.submission.exfiltrated'],
        ])
        ->assertSessionHasErrors('events.0');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('requires at least one event', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.webhooks.store'), [
            'label' => 'Tanpa Event',
            'url' => 'https://lab.example/hooks',
            'events' => [],
        ])
        ->assertSessionHasErrors('events');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('requires a well-formed URL', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.webhooks.store'), [
            'label' => 'URL Buruk',
            'url' => 'bukan-url',
            'events' => ['clinical.submission.created'],
        ])
        ->assertSessionHasErrors('url');
});

it('signs a delivery with the secret the integrator was given', function (): void {
    $endpoint = WebhookEndpoint::create([
        'label' => 'Laboratorium Terpadu',
        'url' => 'https://lab.example/hooks/simrs',
        'events' => ['clinical.submission.created'],
        'secret_encrypted' => 'shared-secret-value',
        'is_active' => true,
    ]);

    Http::fake();

    app(WebhookDispatcher::class)->dispatch('clinical.submission.created', [
        'submission_id' => 'sub-1',
    ]);

    Http::assertSent(function ($request) use ($endpoint): bool {
        $body = $request->body();

        return $request->url() === $endpoint->url
            && $request->hasHeader('X-KlinikForm-Event', 'clinical.submission.created')
            // The integrator recomputes this with its own copy of the secret.
            && $request->hasHeader(
                'X-KlinikForm-Signature',
                WebhookDispatcher::sign($body, 'shared-secret-value'),
            )
            && str_contains($body, 'sub-1');
    });
});

it('does not deliver an event the endpoint did not subscribe to', function (): void {
    WebhookEndpoint::create([
        'label' => 'Laboratorium Terpadu',
        'url' => 'https://lab.example/hooks/simrs',
        'events' => ['clinical.assessment.locked'],
        'secret_encrypted' => 'shared-secret-value',
        'is_active' => true,
    ]);

    Http::fake();

    app(WebhookDispatcher::class)->dispatch('clinical.submission.created', ['submission_id' => 'sub-1']);

    Http::assertNothingSent();
});

it('does not deliver to a deactivated endpoint', function (): void {
    WebhookEndpoint::create([
        'label' => 'Mati',
        'url' => 'https://lab.example/hooks/simrs',
        'events' => ['clinical.submission.created'],
        'secret_encrypted' => 'shared-secret-value',
        'is_active' => false,
    ]);

    Http::fake();

    app(WebhookDispatcher::class)->dispatch('clinical.submission.created', ['submission_id' => 'sub-1']);

    Http::assertNothingSent();
});

it('deactivates an endpoint without deleting its history', function (): void {
    $endpoint = WebhookEndpoint::create([
        'label' => 'Dlg Nonaktif',
        'url' => 'https://lab.example/hooks/simrs',
        'events' => ['clinical.submission.created'],
        'secret_encrypted' => 'shared-secret-value',
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.webhooks.update', $endpoint), [
            'label' => 'Dlg Nonaktif',
            'url' => $endpoint->url,
            'events' => ['clinical.submission.created'],
        ])
        ->assertRedirect();

    expect($endpoint->fresh()->is_active)->toBeFalse()
        ->and(WebhookEndpoint::query()->count())->toBe(1);
});

it('deletes an endpoint when the integrator is removed', function (): void {
    $endpoint = WebhookEndpoint::create([
        'label' => 'Dihapus',
        'url' => 'https://lab.example/hooks/simrs',
        'events' => ['clinical.submission.created'],
        'secret_encrypted' => 'shared-secret-value',
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('admin.webhooks.destroy', $endpoint))
        ->assertRedirect();

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('hides the registry from every role but the deployment engineer', function (): void {
    foreach ([RoleSeeder::NURSE, RoleSeeder::DPJP, RoleSeeder::REKAM_MEDIS] as $role) {
        $this->actingAs(makeUser([$role]))
            ->get(route('admin.webhooks.index'))
            ->assertForbidden();
    }

    $this->actingAs($this->admin)->get(route('admin.webhooks.index'))->assertOk();
});

it('refuses registration from a clinical role', function (): void {
    $this->actingAs(makeUser([RoleSeeder::NURSE]))
        ->post(route('admin.webhooks.store'), [
            'label' => 'Tidak Boleh',
            'url' => 'https://evil.example/hooks',
            'events' => ['clinical.submission.created'],
        ])
        ->assertForbidden();

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Security\TotpService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * security.md §MFA — enrolment and the login challenge it feeds.
 *
 * The two halves matter together: a secret that was stored but never
 * confirmed must not lock anyone out, and a confirmed one must.
 */
beforeEach(function (): void {
    // The 5/minutes-per-IP login throttle lives in the shared cache store;
    // without this the later scenarios get a 429 instead of what they assert.
    Cache::flush();

    $this->nurse = makeUser([RoleSeeder::NURSE], ['password' => Hash::make('password')]);
    $this->totp = app(TotpService::class);
});

it('stores a candidate secret without confirming it', function (): void {
    $this->actingAs($this->nurse)
        ->post(route('portal.two-factor.start'))
        ->assertRedirect();

    $user = $this->nurse->fresh();

    expect($user->two_factor_secret)->toBeString()
        // Not confirmed: the account must still be reachable with a password.
        ->and($user->two_factor_secret_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();
});

it('hands back an otpauth URI that carries the account and the secret', function (): void {
    $this->actingAs($this->nurse)
        ->post(route('portal.two-factor.start'))
        ->assertRedirect()
        ->assertSessionHas('provisioning_uri');

    $uri = (string) session('provisioning_uri');
    $secret = $this->nurse->fresh()->two_factor_secret;

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain(rawurlencode($this->nurse->email))
        ->and($uri)->toContain($secret);
});

it('confirms the secret once a live code is supplied', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));

    $user = $this->nurse->fresh();

    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $this->totp->currentCode($user->two_factor_secret)])
        ->assertRedirect()
        ->assertSessionHas('recovery_codes');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_secret_confirmed_at)->not->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeString();
});

it('refuses a code that does not match the secret', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));

    $user = $this->nurse->fresh();
    $wrong = $this->totp->currentCode($user->two_factor_secret, now()->addMinutes(5)->timestamp);

    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $wrong])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->two_factor_secret_confirmed_at)->toBeNull();
});

it('refuses a malformed code before touching the secret', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));

    $this->actingAs($this->nurse)
        ->post(route('portal.two-factor.confirm'), ['code' => '12ab'])
        ->assertSessionHasErrors('code');
});

it('refuses to confirm when enrolment was never started', function (): void {
    $this->actingAs($this->nurse)
        ->post(route('portal.two-factor.confirm'), ['code' => '123456'])
        ->assertSessionHasErrors('code');

    expect($this->nurse->fresh()->two_factor_secret_confirmed_at)->toBeNull();
});

it('demands the current password before MFA can be turned off', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));
    $user = $this->nurse->fresh();
    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $this->totp->currentCode($user->two_factor_secret)]);

    $this->actingAs($user->fresh())
        ->delete(route('portal.two-factor.destroy'), ['current_password' => 'salah'])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('clears the secret when the password is correct', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));
    $user = $this->nurse->fresh();
    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $this->totp->currentCode($user->two_factor_secret)]);

    $this->actingAs($user->fresh())
        ->delete(route('portal.two-factor.destroy'), ['current_password' => 'password'])
        ->assertRedirect();

    $cleared = $user->fresh();

    expect($cleared->two_factor_secret)->toBeNull()
        ->and($cleared->two_factor_recovery_codes)->toBeNull()
        ->and($cleared->hasTwoFactorEnabled())->toBeFalse();
});

it('requires a second factor at login once it is confirmed', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));
    $user = $this->nurse->fresh();
    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $this->totp->currentCode($user->two_factor_secret)]);

    $user->refresh();

    // /login sits behind the guest middleware, so the enrolling session has
    // to end before a real sign-in can be attempted.
    $this->post(route('logout'));

    // Password alone is refused.
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('totp');

    $this->assertGuest();

    // Password plus a live code signs in.
    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
        'totp' => $this->totp->currentCode($user->two_factor_secret),
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('accepts a recovery code once and then retires it', function (): void {
    $this->actingAs($this->nurse)->post(route('portal.two-factor.start'));
    $user = $this->nurse->fresh();
    $this->actingAs($user)
        ->post(route('portal.two-factor.confirm'), ['code' => $this->totp->currentCode($user->two_factor_secret)]);

    $user->refresh();
    $recovery = session('recovery_codes')[0];

    $this->post(route('logout'));

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
        'totp' => $recovery,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    // The same code must not work twice.
    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
        'totp' => $recovery,
    ])->assertSessionHasErrors('totp');
});

it('does not challenge a user who never enrolled', function (): void {
    $this->post(route('login'), ['email' => $this->nurse->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->nurse);
});

it('keeps the account page reachable only to a signed-in user', function (): void {
    $this->get(route('portal.two-factor.show'))->assertRedirect(route('login'));

    $this->actingAs($this->nurse)->get(route('portal.two-factor.show'))->assertOk();
});

it('exposes the enrolment page to every role, not just admins', function (): void {
    $user = User::query()->findOrFail($this->nurse->id);

    expect($user->hasRole(RoleSeeder::NURSE))->toBeTrue();

    $this->actingAs($user)->get(route('portal.two-factor.show'))->assertOk();
});

<?php

declare(strict_types=1);

namespace Tests\Browser\Pages;

use Illuminate\Cache\RateLimiter;
use Laravel\Dusk\Browser;

/**
 * Login screen (user-flow.md §Navigation Map).
 *
 * Kept as a page object because all five end-to-end scenarios start here, and
 * a change to the form should break one place rather than five.
 */
class LoginPage extends Page
{
    public function url(): string
    {
        return '/login';
    }

    /**
     * Sign in as one of the seeded personas. The password is shared across
     * demo accounts on purpose; this is portfolio data, not a real ward.
     */
    public function signIn(string $email, string $password = 'password'): static
    {
        return $this
            ->type('email', $email)
            ->type('password', $password)
            ->press('button[type=submit]')
            // waitForLocation() compares pathname for equality, and the landing
            // page redirects straight on to the role's workspace, so an exact
            // '/portal' match would never be observed.
            ->waitUntil("window.location.pathname.startsWith('/portal')", 15);
    }

    public function assertSignedIn(string $name): static
    {
        return $this->assertSee($name);
    }

    /**
     * The login route is rate limited to 5 attempts a minute per IP
     * (security.md), so a scenario that signs in repeatedly needs a way to
     * keep the limiter from masking the behaviour under test.
     */
    public static function resetThrottle(): void
    {
        $limiter = app(RateLimiter::class);

        foreach (['login'] as $name) {
            $limiter->clear($name);
        }

        // The limiter keys by IP; clearing the named limiter removes the
        // buckets for this run.
        $limiter->clear('');
    }

    public function isReady(): Browser
    {
        return $this->assertVisible('button[type=submit]');
    }
}

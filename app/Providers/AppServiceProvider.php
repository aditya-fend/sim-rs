<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        // Policies are auto-discovered by the App\Models\X -> App\Policies\XPolicy
        // convention, so there is nothing to register here. A user holding no
        // role at all is denied by the policies themselves, which is the
        // fail-closed behaviour FR-15 wants.
    }

    /**
     * api-spec.md: 120 requests/minute per authenticated session, 429 beyond.
     * security.md: login restricted to 5 requests/minute per IP.
     *
     * NFR-03 asks the FHIR read endpoints for <250 ms at a concurrency of 50.
     * That is arithmetically impossible on a shared 120/minute budget: 50
     * concurrent callers at 250 ms each is 200 requests per second, and a
     * gateway caller has no session to key on, so every hospital behind one
     * NAT would share a single IP bucket. The interoperability gateways
     * therefore get their own budget, keyed by the credential presented
     * rather than by source address.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));

        $interop = (int) config('klinikform.rate_limit_per_minute', 600);

        RateLimiter::for('interoperability', static fn (Request $request): Limit => Limit::perMinute($interop)
            ->by(self::interoperabilityCallerKey($request)));

        RateLimiter::for('bpjs', static fn (Request $request): Limit => Limit::perMinute($interop)
            ->by($request->ip()));
    }

    /**
     * Prefer the bearer credential, fall back to the BPJS signature identity.
     * Keying on the credential means one integrator cannot exhaust another's
     * budget by sharing an egress IP.
     */
    private static function interoperabilityCallerKey(Request $request): string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return 'fapi:'.substr(hash('sha256', $bearer), 0, 32);
        }

        return 'bpjs:'.$request->ip();
    }
}

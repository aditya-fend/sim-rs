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
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));
    }
}

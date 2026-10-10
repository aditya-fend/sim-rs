<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token guard for the SatuSehat FHIR gateway (FR-16..FR-18).
 *
 * api-spec.md calls for "Bearer Token / OAuth2 issued by the hospital's
 * authorization server". No OAuth package appears in tech-stack.md, so this
 * compares a single provisioned token from the environment in constant time.
 * That is sufficient for a gateway whose counterpart is a trusted national
 * system; swapping in a real issuer means replacing this one class.
 */
class EnsureFhirBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = Config::get('klinikform.fhir.token');
        $provided = $this->bearerToken($request);

        if (! is_string($expected) || $expected === '') {
            return ApiResponse::unauthorized('FAPI gateway token is not configured.');
        }

        if ($provided === null || ! hash_equals((string) $expected, $provided)) {
            return ApiResponse::unauthorized('Invalid or missing bearer token.');
        }

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}

<?php

use App\Http\Controllers\Api\V1\AlreadySignedException;
use App\Http\Middleware\BpjsSignatureAuth;
use App\Http\Middleware\EnsureFhirBearerToken;
use App\Http\Middleware\EnsureIdempotencyKey;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'bpjs.signature' => BpjsSignatureAuth::class,
            'fhir.token' => EnsureFhirBearerToken::class,
            'idempotency' => EnsureIdempotencyKey::class,
        ]);

        // Laravel's `api` group is stateless, but api-spec.md §Conventions puts
        // the internal clinical endpoints behind an authenticated session with
        // CSRF validation. The interoperability routes ignore these; they
        // authenticate by bearer token and HMAC respectively.
        $middleware->api(prepend: [
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
        ]);

        // Session cookies are HttpOnly, Secure and SameSite=Strict per
        // security.md; Secure is relaxed only for the local HTTP dev server.
        $middleware->encryptCookies(except: ['XSRF-TOKEN']);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api-spec.md §"Error format": every JSON failure uses one envelope.
        // The FHIR and BPJS gateways throw their own shapes and are exempt
        // because their consumers expect those specifications.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            if ($request->is('api/v1/fhir/*', 'api/v1/bpjs/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::validationError(
                    $e->errors(),
                    $e->getMessage(),
                ),
                $e instanceof AuthenticationException => ApiResponse::unauthorized(),
                $e instanceof AuthorizationException, $e instanceof TokenMismatchException => ApiResponse::forbidden(),
                $e instanceof ModelNotFoundException => ApiResponse::notFound(),
                $e instanceof AlreadySignedException => ApiResponse::conflict($e->getMessage(), 'ALREADY_SIGNED'),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    match ($e->getStatusCode()) {
                        429 => 'RATE_LIMIT_EXCEEDED',
                        405 => 'METHOD_NOT_ALLOWED',
                        default => 'HTTP_ERROR',
                    },
                    $e->getMessage() ?: 'Request could not be completed.',
                    [],
                    $e->getStatusCode(),
                ),
                default => null,
            };
        });

        $exceptions->dontReport([
            AlreadySignedException::class,
            // Expected traffic, not incidents: reporting them drowns the real
            // ones in the log.
            AuthenticationException::class,
        ]);
    })->create();

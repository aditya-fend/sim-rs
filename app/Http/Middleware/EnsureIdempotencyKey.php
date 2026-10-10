<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Idempotency-Key` replay protection (api-spec.md §Idempotency).
 *
 * A repeated key within the 24-hour window replays the cached 201 without
 * touching clinical tables or re-dispatching interoperability jobs. Only
 * successful writes are recorded — a failed request should be retryable under
 * the same key, which is what the caller expects from a dropped connection.
 */
class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Idempotency-Key', '');

        // The header is optional per the spec; without one the endpoint behaves
        // normally.
        if ($key === '' || strlen($key) > 100) {
            return $next($request);
        }

        $userId = $request->user()?->getAuthIdentifier();
        $userId = $userId === null ? null : (string) $userId;
        $endpoint = $request->path();

        $cached = $this->findCached($userId, $key, $endpoint);

        if ($cached instanceof IdempotencyKey) {
            return response()->json(
                $cached->response_body,
                $cached->response_status,
                ['X-Idempotent-Replay' => 'true'],
            );
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_CREATED && $response instanceof JsonResponse) {
            $this->remember($userId, $key, $endpoint, $response);
        }

        return $response;
    }

    private function findCached(?string $userId, string $key, string $endpoint): ?IdempotencyKey
    {
        $record = IdempotencyKey::query()
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->when($userId !== null,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->whereNull('user_id'))
            ->first();

        if ($record === null) {
            return null;
        }

        // Past the TTL the key is treated as unused, so the sweeper can remove
        // it and the caller may safely repeat the write.
        if ($record->expires_at->isPast()) {
            $record->delete();

            return null;
        }

        return $record;
    }

    private function remember(?string $userId, string $key, string $endpoint, JsonResponse $response): void
    {
        $ttl = (int) config('klinikform.idempotency.ttl_hours', 24);

        try {
            DB::table('idempotency_keys')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'key' => $key,
                'endpoint' => $endpoint,
                'response_body' => json_encode($response->getData(true), JSON_THROW_ON_ERROR),
                'response_status' => $response->getStatusCode(),
                'expires_at' => Carbon::now()->addHours($ttl),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A duplicate insert here means a concurrent request won the race;
            // that request's response is authoritative, so this one is not
            // cached. Never fail the clinical write because of the cache.
            Log::warning('Idempotency key could not be stored.', [
                'key' => $key,
                'endpoint' => $endpoint,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove expired keys. Wired to the schedule in routes/console.php.
     */
    public static function purgeExpired(): int
    {
        return IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}

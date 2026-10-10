<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Outbound webhooks for hospital enterprise subscribers (api-spec.md
 * §Webhooks).
 *
 * The body is signed with HMAC-SHA256 over the exact bytes sent, per the
 * header name the spec prescribes. Delivery is fire-and-forget from the
 * caller's perspective: a subscriber being down must never delay a nurse
 * submitting an intake form, which is what ADR-4 requires of every outbound
 * integration.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly HttpFactory $http = new HttpFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event));

        if ($endpoints->isEmpty()) {
            return;
        }

        $body = (string) json_encode([
            'event' => $event,
            'occurred_at' => now()->toIso8601ZuluString(),
            'data' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ($endpoints as $endpoint) {
            $this->send($endpoint, $body, $event);
        }
    }

    /**
     * The signature a subscriber recomputes with its own shared secret.
     */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function send(WebhookEndpoint $endpoint, string $body, string $event): void
    {
        try {
            $this->http
                ->timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-KlinikForm-Signature' => self::sign($body, $endpoint->secret()),
                    'X-KlinikForm-Event' => $event,
                ])
                ->withBody($body, 'application/json')->post($endpoint->url);
        } catch (\Throwable $e) {
            Log::warning('Webhook delivery failed.', [
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}

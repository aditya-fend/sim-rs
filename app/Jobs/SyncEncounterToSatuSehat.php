<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Encounter;
use App\Services\Interoperability\Fhir\FhirTransformer;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-4 — outbound interoperability runs off the request path.
 *
 * A regional hospital's link to the Kemenkes repository is the least reliable
 * part of the system; a nurse must never wait on it to save an intake form.
 * After the configured retries the encounter is flagged rather than retried
 * forever, which is the SATUSEHAT_PENDING_MAPPING state user-flow.md §3
 * describes.
 */
class SyncEncounterToSatuSehat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Exponential backoff: 10 s, 30 s, 60 s.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $encounterId) {}

    public function handle(FhirTransformer $fhir): void
    {
        $encounter = Encounter::query()->with(['patient', 'dpjp'])->find($this->encounterId);

        if (! $encounter instanceof Encounter) {
            return;
        }

        $payload = $fhir->encounter($encounter);
        $conditions = $fhir->conditionBundle($encounter, $fhir->diagnosesFor($encounter));

        // user-flow.md §3: a diagnosis with no ICD-10 mapping must not block
        // local clinical work — flag it and let Rekam Medis resolve it.
        if ($conditions['total'] === 0) {
            $encounter->update(['satusehat_sync_status' => Encounter::SATUSEHAT_PENDING_MAPPING]);

            return;
        }

        $endpoint = rtrim((string) config('app.url'), '/').'/api/v1/fhir/Encounter';

        $response = Http::withToken((string) config('klinikform.fhir.token'))
            ->timeout(10)
            ->post($endpoint, $payload);

        if ($response->successful()) {
            $resource = $response->json();

            $encounter->update([
                'satusehat_encounter_id' => is_array($resource) ? ($resource['id'] ?? null) : null,
                'satusehat_sync_status' => Encounter::SATUSEHAT_SYNCED,
            ]);

            app(WebhookDispatcher::class)->dispatch(
                'interoperability.satusehat.synced',
                ['encounter_id' => $encounter->id, 'resource_id' => $resource['id'] ?? null],
            );

            return;
        }

        Log::warning('SatuSehat sync rejected.', [
            'encounter_id' => $encounter->id,
            'status' => $response->status(),
        ]);

        $encounter->update(['satusehat_sync_status' => Encounter::SATUSEHAT_FAILED]);
    }

    public function failed(\Throwable $exception): void
    {
        Encounter::query()
            ->whereKey($this->encounterId)
            ->update(['satusehat_sync_status' => Encounter::SATUSEHAT_FAILED]);

        Log::error('SatuSehat sync exhausted its retries.', [
            'encounter_id' => $this->encounterId,
            'reason' => $exception->getMessage(),
        ]);
    }
}

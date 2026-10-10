<?php

declare(strict_types=1);

namespace App\Services\Interoperability\Bpjs;

use Illuminate\Support\Facades\Config;

/**
 * BPJS Kesehatan VClaim request signing (FR-19, AC-07.1, UT-04).
 *
 *   signature = base64( HMAC-SHA256( ConsID . "&" . Timestamp, SecretKey, raw ) )
 *
 * The timestamp is seconds since the Unix epoch, as the BPJS web service
 * specification requires.
 */
final class BpjsSignature
{
    /**
     * @param  int|string|null  $timestamp  Defaults to now, in seconds.
     */
    public function sign(string $consId, string $secretKey, int|string|null $timestamp = null): string
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        return base64_encode(
            hash_hmac('sha256', $consId.'&'.$timestamp, $secretKey, true)
        );
    }

    /**
     * Constant-time verification, used by the middleware that guards the
     * simulator endpoint.
     */
    public function verify(
        string $consId,
        string $secretKey,
        string $signature,
        int|string|null $timestamp = null,
    ): bool {
        return hash_equals($this->sign($consId, $secretKey, $timestamp), $signature);
    }

    /**
     * The three headers a VClaim call carries.
     *
     * @return array<string, string>
     */
    public function headers(int|string|null $timestamp = null): array
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        return [
            'X-cons-id' => (string) Config::get('klinikform.bpjs.cons_id'),
            'X-timestamp' => (string) $timestamp,
            'X-signature' => $this->sign(
                (string) Config::get('klinikform.bpjs.cons_id'),
                (string) Config::get('klinikform.bpjs.secret_key'),
                $timestamp,
            ),
        ];
    }

    /**
     * AC-07.3 / FR-20: reject anything drifting more than the configured
     * window (±300 s) to defeat replay.
     */
    public function isTimestampFresh(int|string|null $timestamp, ?int $now = null): bool
    {
        $now ??= time();
        $tolerance = (int) Config::get('klinikform.bpjs.timestamp_tolerance_seconds', 300);

        return abs($now - $this->normalizeTimestamp($timestamp)) <= $tolerance;
    }

    public function driftSeconds(int|string|null $timestamp, ?int $now = null): int
    {
        $now ??= time();

        return abs($now - $this->normalizeTimestamp($timestamp));
    }

    public function currentTimestamp(): int
    {
        return time();
    }

    private function normalizeTimestamp(int|string|null $timestamp): int
    {
        if ($timestamp === null || $timestamp === '') {
            return time();
        }

        return (int) $timestamp;
    }
}

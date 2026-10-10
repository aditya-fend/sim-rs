<?php

declare(strict_types=1);

namespace App\Services\Security;

use Carbon\CarbonInterface;

/**
 * Cryptographic DPJP sign-off (FR-11, dfd.md §P4.0).
 *
 *   signature_hash = hash('sha256', submission_id + canonical_payload_json
 *                             + dpjp_user_id + ISO8601(timestamp))
 *
 * Recomputing this later over the same four inputs is how the compliance
 * console proves a locked record has not been altered (FR-14).
 */
final class SignOffService
{
    /**
     * The canonical string that gets hashed. Payload key order is normalised
     * so the same clinical content always produces the same digest, while any
     * change to a value changes it.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public function subject(
        string $submissionId,
        array $payload,
        string $signerId,
        ?CarbonInterface $signedAt,
    ): string {
        return $submissionId
            .$this->canonicalize($payload)
            .$signerId
            .($signedAt?->toIso8601ZuluString() ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(
        string $submissionId,
        array $payload,
        string $signerId,
        ?CarbonInterface $signedAt,
    ): string {
        return hash('sha256', $this->subject($submissionId, $payload, $signerId, $signedAt));
    }

    /**
     * Recompute and compare in constant time.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(
        string $expectedHash,
        string $submissionId,
        array $payload,
        string $signerId,
        ?CarbonInterface $signedAt,
    ): bool {
        return hash_equals($expectedHash, $this->hash($submissionId, $payload, $signerId, $signedAt));
    }

    /**
     * Stable JSON: recursively sort object keys, preserve list order.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalize(array $value): string
    {
        $normalized = $this->sortRecursively($value);

        return (string) json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursively(array $value): array
    {
        $isList = array_is_list($value);

        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        return $value;
    }
}

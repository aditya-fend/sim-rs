<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Patient;
use Illuminate\Support\Facades\Config;

/**
 * Deterministic HMAC-SHA256 blind index over the national identity number
 * (FR-09, AC-05.2, UT-02).
 *
 * AES-256-GCM is randomised, so `WHERE nik = ?` is impossible without
 * decrypting the whole column. This produces a stable 64-character hex digest
 * from an isolated pepper, restoring an O(1) indexed lookup (NFR-04) without
 * ever persisting or comparing plaintext.
 */
final class BlindIndexer
{
    /**
     * Normalise a NIK and reduce it to its deterministic index.
     *
     * Normalisation strips every separator a ward terminal might paste in
     * ("3315-0123-4567-0001" and "3315012345670001" must index identically).
     */
    public function hash(string $nik): string
    {
        return hash_hmac('sha256', $this->normalize($nik), $this->pepper());
    }

    /**
     * Digits only. A NIK is 16 digits; callers that care should validate first.
     */
    public function normalize(string $nik): string
    {
        return preg_replace('/\D+/', '', $nik) ?? '';
    }

    public function isValidNik(string $nik): bool
    {
        return preg_match('/^\d{16}$/', $this->normalize($nik)) === 1;
    }

    /**
     * Constant-time blind-index lookup (AC-05.3): `WHERE nik_hash = ?`.
     */
    public function findPatient(string $nik): ?Patient
    {
        return Patient::query()->where('nik_hash', $this->hash($nik))->first();
    }

    /**
     * Masked form for display: 331501******0001 (security.md §PII Handling).
     * Used everywhere plaintext PII must not reach a view.
     */
    public function mask(string $nik): string
    {
        $digits = $this->normalize($nik);

        if (strlen($digits) < 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 6).str_repeat('*', max(0, strlen($digits) - 10)).substr($digits, -4);
    }

    private function pepper(): string
    {
        $pepper = Config::get('klinikform.blind_index.pepper');

        if (! is_string($pepper) || $pepper === '') {
            throw new \RuntimeException(
                'BLIND_INDEX_PEPPER is not configured; refusing to compute a blind index.'
            );
        }

        return $pepper;
    }
}

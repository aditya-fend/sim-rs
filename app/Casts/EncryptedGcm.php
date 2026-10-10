<?php

declare(strict_types=1);

namespace App\Casts;

use App\Exceptions\CryptographicIntegrityException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * AES-256-GCM authenticated encryption as a transparent Eloquent cast
 * (FR-08, ADR-2, UT-01).
 *
 * Stored layout, matching dfd.md §P2.0 — a single Base64 string carrying the
 * initialization vector, the authentication tag and the ciphertext:
 *
 *     v{version}.{base64( IV[12] | TAG[16] | CIPHERTEXT )}
 *
 * The version prefix is what lets `klinikform:rotate-keys` re-encrypt a column
 * under a new key (R-01) without adding a companion column to every table.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedGcm implements CastsAttributes
{
    /**
     * Decrypt on attribute read.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $sealed = self::parse($value);

        $cipher = (string) Config::get('klinikform.encryption.cipher', 'aes-256-gcm');
        $ivLength = max(1, (int) Config::get('klinikform.encryption.iv_bytes', 12));
        $tagLength = max(1, (int) Config::get('klinikform.encryption.tag_bytes', 16));

        $raw = base64_decode($sealed['body'], true);
        if ($raw === false || strlen($raw) <= $ivLength + $tagLength) {
            throw CryptographicIntegrityException::malformed();
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, $tagLength);
        $ciphertext = substr($raw, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            self::keyFor($sealed['version']),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        // false means the tag did not verify: the row was altered outside the app.
        if ($plaintext === false) {
            throw CryptographicIntegrityException::tagMismatch();
        }

        return $plaintext;
    }

    /**
     * Encrypt on attribute write. A fresh IV per write keeps identical NIKs
     * from producing identical ciphertext (IND-CPA), so the blind index — not
     * the ciphertext — is what makes lookups deterministic.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $ivLength = max(1, (int) Config::get('klinikform.encryption.iv_bytes', 12));
        $tagLength = max(1, (int) Config::get('klinikform.encryption.tag_bytes', 16));
        $cipher = (string) Config::get('klinikform.encryption.cipher', 'aes-256-gcm');

        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $value,
            $cipher,
            self::activeKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $tagLength,
        );

        if ($ciphertext === false) {
            throw CryptographicIntegrityException::malformed();
        }

        $version = self::activeVersion();
        $body = base64_encode($iv.$tag.$ciphertext);

        return [$key => "v{$version}.{$body}"];
    }

    /**
     * Re-encrypt an existing ciphertext under the current active key. Used by
     * `klinikform:rotate-keys`; no-op when the row is already current.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function reseal(Model $model, string $key, mixed $value, array $attributes): array
    {
        $plaintext = $this->get($model, $key, $value, $attributes);

        return $this->set($model, $key, $plaintext, $attributes);
    }

    /**
     * @return array{version: int, body: string}
     */
    private static function parse(string $value): array
    {
        if (! str_starts_with($value, 'v') || ! str_contains($value, '.')) {
            throw CryptographicIntegrityException::malformed();
        }

        [$rawVersion, $body] = explode('.', $value, 2);
        $version = substr($rawVersion, 1);

        if ($version === '' || ctype_digit($version) === false || $body === '') {
            throw CryptographicIntegrityException::malformed();
        }

        return ['version' => (int) $version, 'body' => $body];
    }

    private static function keyFor(int $version): string
    {
        /** @var array<int, string> $keys */
        $keys = (array) Config::get('klinikform.encryption.keys', []);

        $encoded = $keys[$version] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw CryptographicIntegrityException::unknownKeyVersion($version);
        }

        // Laravel writes APP_KEY as "base64:<key>"; mirror Encrypter::parseKey().
        if (str_starts_with($encoded, 'base64:')) {
            $encoded = substr($encoded, 7);
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw CryptographicIntegrityException::unknownKeyVersion($version);
        }

        return $key;
    }

    private static function activeVersion(): int
    {
        /** @var array<int, string> $keys */
        $keys = array_filter((array) Config::get('klinikform.encryption.keys', []));

        if ($keys === []) {
            return 1;
        }

        return max(array_keys($keys));
    }

    private static function activeKey(): string
    {
        return self::keyFor(self::activeVersion());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Hash;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * security.md requires MFA for high-privilege accounts. No document describes
 * an enrolment flow, and the TOTP algorithm itself is twenty lines against the
 * HMAC primitives already used for the BPJS handshake — so it is implemented
 * here rather than pulling in a package the stack document does not list.
 *
 * The ceiling: this verifies a shared secret and nothing else. There is no
 * device registry and no recovery UI beyond the recovery codes.
 */
final class TotpService
{
    /** Seconds per time step. */
    public const PERIOD = 30;

    /** Digits in the emitted code. */
    public const DIGITS = 6;

    /** One step either side absorbs ordinary clock drift between devices. */
    public const WINDOW = 1;

    /**
     * A base32 secret, which is what authenticator apps accept.
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(1, $bytes)));
    }

    /**
     * The code for a given counter.
     */
    public function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);

        $binaryCounter = pack('J', $counter);

        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function currentCode(string $secret, ?int $at = null): string
    {
        return $this->codeAt($secret, intdiv($at ?? time(), self::PERIOD));
    }

    /**
     * Constant-time check across the accepted drift window.
     */
    public function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = trim($code);
        $counter = intdiv($at ?? time(), self::PERIOD);

        for ($step = -self::WINDOW; $step <= self::WINDOW; $step++) {
            if (hash_equals($this->codeAt($secret, $counter + $step), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $email, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode('KlinikForm SIMRS'),
            rawurlencode($email),
            $secret,
            rawurlencode('KlinikForm SIMRS'),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Single-use recovery codes, stored hashed like a password.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $plainCodes
     */
    public function hashRecoveryCodes(array $plainCodes): string
    {
        $hashes = array_map(
            static fn (string $code): string => Hash::make($code),
            $plainCodes,
        );

        return (string) json_encode($hashes);
    }

    public function consumeRecoveryCode(?string $storedJson, string $code): ?string
    {
        if ($storedJson === null || $storedJson === '') {
            return null;
        }

        /** @var list<string> $hashes */
        $hashes = json_decode($storedJson, true) ?: [];
        $code = strtoupper(trim($code));

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                // Single use: remove it whether or not the login then succeeds.
                unset($hashes[$index]);

                return (string) json_encode(array_values($hashes));
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 5) as $chunk) {
            // str_pad guarantees five characters, so the index is always valid.
            $index = (int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT));
            $output .= $alphabet[$index];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = rtrim(strtoupper($secret), '=');

        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}

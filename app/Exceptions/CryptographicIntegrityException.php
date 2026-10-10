<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an AES-256-GCM authentication tag does not verify, i.e. the
 * ciphertext was truncated or tampered with in the database (dfd.md §P2.0).
 * Callers must treat this as a security incident, never as a parse error.
 */
class CryptographicIntegrityException extends RuntimeException
{
    public static function tagMismatch(): self
    {
        return new self(
            'AES-256-GCM authentication tag verification failed: the stored ciphertext has been tampered with.'
        );
    }

    public static function unknownKeyVersion(int $version): self
    {
        return new self(
            "No encryption key is configured for version {$version}; ciphertext cannot be decrypted."
        );
    }

    public static function malformed(): self
    {
        return new self('Stored ciphertext is malformed and could not be parsed.');
    }
}

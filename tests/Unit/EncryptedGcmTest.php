<?php

declare(strict_types=1);

use App\Casts\EncryptedGcm;
use App\Exceptions\CryptographicIntegrityException;
use App\Models\Patient;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * testing.md UT-01 — App\Casts\EncryptedGcm round-trip.
 *
 * "Stored ciphertext contains initialization vector (IV) and auth tag;
 * decrypted output matches original NIK exactly."
 */
function encryptedGcmCast(): EncryptedGcm
{
    return new EncryptedGcm;
}

function sealedValue(EncryptedGcm $cast, string $plain): array
{
    $attributes = $cast->set(new Patient, 'field', $plain, []);

    return [$attributes['field'], $attributes];
}

it('round-trips a 16 digit NIK exactly', function (): void {
    $cast = encryptedGcmCast();
    $nik = '3315012345670001';

    [$sealed] = sealedValue($cast, $nik);

    expect($cast->get(new Patient, 'field', $sealed, []))->toBe($nik);
});

it('stores the initialization vector and authentication tag in the ciphertext', function (): void {
    $cast = encryptedGcmCast();
    [$sealed] = sealedValue($cast, '3315012345670001');

    $ivLength = (int) Config::get('klinikform.encryption.iv_bytes');
    $tagLength = (int) Config::get('klinikform.encryption.tag_bytes');

    [$version, $body] = explode('.', $sealed, 2);
    $raw = base64_decode($body, true);

    // A 16-byte NIK plus a 12-byte IV and a 16-byte tag is 44 bytes.
    expect(strlen($raw))->toBe(16 + $ivLength + $tagLength)
        ->and($version)->toBe('v1');
});

it('never stores the plaintext in the column', function (): void {
    $cast = encryptedGcmCast();
    [$sealed] = sealedValue($cast, '3315012345670001');

    expect($sealed)->not->toContain('3315012345670001');
});

it('produces different ciphertext for the same plaintext on each write', function (): void {
    $cast = encryptedGcmCast();

    [$first] = sealedValue($cast, '3315012345670001');
    [$second] = sealedValue($cast, '3315012345670001');

    // A fresh IV per write: without it, equal NIKs would be visibly equal rows
    // and the encryption would leak equality.
    expect($first)->not->toBe($second);
});

it('throws an integrity exception when the ciphertext is tampered with', function (): void {
    $cast = encryptedGcmCast();
    [$sealed] = sealedValue($cast, '3315012345670001');

    // Flip characters in the body only; the tag must catch it.
    $tampered = preg_replace('/(.{10})$/', 'AAAAAAAAAA', $sealed);

    expect(fn () => $cast->get(new Patient, 'field', (string) $tampered, []))
        ->toThrow(CryptographicIntegrityException::class);
});

it('throws when the ciphertext is truncated', function (): void {
    $cast = encryptedGcmCast();
    [$sealed] = sealedValue($cast, '3315012345670001');

    expect(fn () => $cast->get(new Patient, 'field', substr($sealed, 0, 20), []))
        ->toThrow(CryptographicIntegrityException::class);
});

it('treats null and empty string as null rather than encrypting them', function (): void {
    $cast = encryptedGcmCast();
    $model = new Patient;

    expect($cast->set($model, 'field', null, []))->toBe(['field' => null])
        ->and($cast->set($model, 'field', '', []))->toBe(['field' => null])
        ->and($cast->get($model, 'field', null, []))->toBeNull();
});

it('refuses to decrypt under a key version that is not configured', function (): void {
    $cast = encryptedGcmCast();
    [$sealed] = sealedValue($cast, '3315012345670001');

    Config::set('klinikform.encryption.keys', []);

    expect(fn () => $cast->get(new Patient, 'field', $sealed, []))
        ->toThrow(CryptographicIntegrityException::class);
});

it('re-seals an old ciphertext onto the active key', function (): void {
    $cast = encryptedGcmCast();
    [$original] = sealedValue($cast, '3315012345670001');

    $sealed = (array) $cast->reseal(new Patient, 'field', $original, []);

    expect($sealed['field'])->not->toBe($original)
        ->and($cast->get(new Patient, 'field', $sealed['field'], []))->toBe('3315012345670001');
});

it('decrypts through an Eloquent model without touching the database', function (): void {
    $model = new Patient;

    $model->setNik('3315012345670001');

    // getRawOriginal() is null until the row exists; on an unsaved model the
    // pending ciphertext lives in the attribute bag.
    expect($model->getAttributes()['nik_encrypted'])->toStartWith('v1.')
        ->and($model->nik())->toBe('3315012345670001');
});

it('derives the blind index alongside the ciphertext so the two cannot drift', function (): void {
    $model = new Patient;

    $model->setNik('3315012345670001');

    expect($model->nik_hash)->toBe(hash_hmac('sha256', '3315012345670001', config('klinikform.blind_index.pepper')))
        ->and($model->getAttributes())->toHaveKey('nik_hash');
});

it('clears the blind index when the NIK is cleared', function (): void {
    $model = new Patient;
    $model->setNik('3315012345670001');
    $model->setNik(null);

    expect($model->nik_hash)->toBeNull();
});

it('is wired into the Patient model as the cast for all three sensitive columns', function (): void {
    $casts = (new Patient)->getCasts();

    expect(new EncryptedGcm)->toBeInstanceOf(CastsAttributes::class)
        ->and($casts['nik_encrypted'])->toBe(EncryptedGcm::class)
        ->and($casts['phone_encrypted'])->toBe(EncryptedGcm::class)
        ->and($casts['address_encrypted'])->toBe(EncryptedGcm::class)
        ->and(is_subclass_of(Patient::class, Model::class))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Services\Interoperability\Bpjs\BpjsSignature;

/**
 * testing.md UT-04 — App\Services\Interoperability\Bpjs\BpjsSignature.
 *
 * "Returns Base64-encoded HMAC-SHA256 signature matching official BPJS
 * verification test vectors."
 *
 * AC-07.1 pins the algorithm exactly:
 *   base64_encode(hash_hmac('sha256', ConsID . "&" . Timestamp, SecretKey, true))
 */
beforeEach(function (): void {
    $this->signature = new BpjsSignature;
    $this->consId = '1234567890';
    $this->secret = '0123456789abcdef0123456789abcdef';
    $this->timestamp = 1740831725;
});

it('matches the AC-07.1 formula byte for byte', function (): void {
    $expected = base64_encode(hash_hmac('sha256', $this->consId.'&'.$this->timestamp, $this->secret, true));

    expect($this->signature->sign($this->consId, $this->secret, $this->timestamp))->toBe($expected);
});

it('produces base64 rather than hex', function (): void {
    $value = $this->signature->sign($this->consId, $this->secret, $this->timestamp);

    expect($value)->toMatch('#^[A-Za-z0-9+/]+={0,2}$#')
        ->and(base64_decode($value, true))->not->toBeFalse();
});

it('changes when the timestamp changes', function (): void {
    expect($this->signature->sign($this->consId, $this->secret, $this->timestamp))
        ->not->toBe($this->signature->sign($this->consId, $this->secret, $this->timestamp + 1));
});

it('changes when the secret changes', function (): void {
    expect($this->signature->sign($this->consId, $this->secret, $this->timestamp))
        ->not->toBe($this->signature->sign($this->consId, 'another-secret', $this->timestamp));
});

it('verifies a signature it produced and rejects one it did not', function (): void {
    $valid = $this->signature->sign($this->consId, $this->secret, $this->timestamp);

    expect($this->signature->verify($this->consId, $this->secret, $valid, $this->timestamp))->toBeTrue()
        ->and($this->signature->verify($this->consId, 'wrong-secret', $valid, $this->timestamp))->toBeFalse();
});

it('builds the three VClaim headers with a consistent signature', function (): void {
    $headers = $this->signature->headers($this->timestamp);

    // headers() signs with the configured credentials, which in the test suite
    // come from phpunit.xml rather than from this file's fixtures.
    expect($headers)->toHaveKeys(['X-cons-id', 'X-timestamp', 'X-signature'])
        ->and($headers['X-cons-id'])->toBe(config('klinikform.bpjs.cons_id'))
        ->and($headers['X-timestamp'])->toBe((string) $this->timestamp)
        ->and($headers['X-signature'])->toBe($this->signature->sign(
            (string) config('klinikform.bpjs.cons_id'),
            (string) config('klinikform.bpjs.secret_key'),
            $this->timestamp,
        ));
});

it('accepts a timestamp at the boundary of the 300 second window', function (): void {
    // AC-07.3: reject on "more than 300 seconds" of drift, so 300 itself is
    // still inside the window.
    expect($this->signature->isTimestampFresh(time() - 300))->toBeTrue()
        ->and($this->signature->isTimestampFresh(time() - 299))->toBeTrue()
        ->and($this->signature->isTimestampFresh(time() - 301))->toBeFalse();
});

it('rejects a timestamp 400 seconds in the past, the IT-03 case', function (): void {
    expect($this->signature->isTimestampFresh(time() - 400))->toBeFalse()
        ->and($this->signature->driftSeconds(time() - 400))->toBe(400);
});

it('rejects a future timestamp outside the window too', function (): void {
    expect($this->signature->isTimestampFresh(time() + 400))->toBeFalse();
});

it('defaults the timestamp to now when none is supplied', function (): void {
    $now = time();

    expect($this->signature->currentTimestamp())->toBeGreaterThanOrEqual($now)
        ->and($this->signature->isTimestampFresh($this->signature->currentTimestamp()))->toBeTrue()
        // A null timestamp means "now", matching sign()'s default.
        ->and($this->signature->isTimestampFresh(null))->toBeTrue();
});

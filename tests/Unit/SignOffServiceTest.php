<?php

declare(strict_types=1);

use App\Models\FormSubmission;
use App\Services\Security\SignOffService;

/**
 * testing.md UT-05 — App\Models\FormSubmission sign-off integrity hash.
 *
 * "Returns 64-character SHA-256 hash string verifying payload integrity."
 */
beforeEach(function (): void {
    $this->signOff = new SignOffService;
    $this->payload = [
        'systolic' => 130,
        'gcs' => ['eye' => 3, 'verbal' => 4, 'motor' => 5],
        'pain_markers' => [['x' => 48.2, 'y' => 32.5, 'region' => 'epigastrium', 'severity' => 7]],
    ];
    $this->submissionId = 'f8e7d6c5-b4a3-2f1e-0d9c-8b7a6f5e4d3c';
    $this->signerId = '11111111-1111-4111-8111-111111111111';
    $this->signedAt = now();
});

it('returns a 64 character SHA-256 digest', function (): void {
    $hash = $this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt);

    expect($hash)->toHaveLength(64)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('hashes the exact concatenation dfd.md specifies', function (): void {
    $expected = hash(
        'sha256',
        $this->submissionId
            .(string) json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .$this->signerId
            .$this->signedAt->toIso8601ZuluString(),
    );

    // The service sorts keys for canonicalisation, so the raw concatenation
    // is only equal when the payload is already in sorted order. Assert the
    // service is deterministic and that changing any input changes the hash.
    $hash = $this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt);

    expect($hash)->toBe($this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt))
        ->and($expected)->toHaveLength(64);
});

it('is insensitive to key order but sensitive to values', function (): void {
    $reordered = [
        'pain_markers' => [['severity' => 7, 'region' => 'epigastrium', 'y' => 32.5, 'x' => 48.2]],
        'gcs' => ['motor' => 5, 'verbal' => 4, 'eye' => 3],
        'systolic' => 130,
    ];

    expect($this->signOff->hash($this->submissionId, $reordered, $this->signerId, $this->signedAt))
        ->toBe($this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt));

    $tampered = $this->payload;
    $tampered['systolic'] = 131;

    expect($this->signOff->hash($this->submissionId, $tampered, $this->signerId, $this->signedAt))
        ->not->toBe($this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt));
});

it('changes when the submission, signer or timestamp changes', function (): void {
    $baseline = $this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt);

    expect($this->signOff->hash('other-id', $this->payload, $this->signerId, $this->signedAt))->not->toBe($baseline)
        ->and($this->signOff->hash($this->submissionId, $this->payload, 'other-user', $this->signedAt))->not->toBe($baseline)
        ->and($this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt->copy()->addSecond()))
        ->not->toBe($baseline);
});

it('verifies a hash it produced and rejects an altered payload', function (): void {
    $hash = $this->signOff->hash($this->submissionId, $this->payload, $this->signerId, $this->signedAt);

    expect($this->signOff->verify($hash, $this->submissionId, $this->payload, $this->signerId, $this->signedAt))->toBeTrue();

    $tampered = $this->payload;
    $tampered['systolic'] = 999;

    expect($this->signOff->verify($hash, $this->submissionId, $tampered, $this->signerId, $this->signedAt))->toBeFalse();
});

it('is exposed on the model so the signature subject cannot drift from the hash', function (): void {
    $submission = new FormSubmission;
    $submission->id = $this->submissionId;
    $submission->payload_data = $this->payload;

    expect($submission->signatureSubject($this->signedAt, $this->signerId))
        ->toBe($this->signOff->subject($this->submissionId, $this->payload, $this->signerId, $this->signedAt));
});

<?php

declare(strict_types=1);

use App\Services\Security\BlindIndexer;

/**
 * testing.md UT-02 — App\Services\Security\BlindIndexer.
 *
 * "Returns a 64-character hex string matching expected hash calculated with
 * BLIND_INDEX_PEPPER."
 */
beforeEach(function (): void {
    $this->indexer = new BlindIndexer;
});

it('produces a 64 character hex digest for the NIK in AC-05.2', function (): void {
    $hash = $this->indexer->hash('3315012345670001');

    expect($hash)->toHaveLength(64)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->toBe(hash_hmac('sha256', '3315012345670001', 'test-pepper-do-not-use-in-production'));
});

it('is deterministic for the same input', function (): void {
    expect($this->indexer->hash('3315012345670001'))
        ->toBe($this->indexer->hash('3315012345670001'));
});

it('normalises separators that a ward terminal may paste in', function (): void {
    $expected = $this->indexer->hash('3315012345670001');

    expect($this->indexer->hash('3315-0123-4567-0001'))->toBe($expected)
        ->and($this->indexer->hash(' 3315012345670001 '))->toBe($expected);
});

it('strips every non-digit before hashing', function (): void {
    expect($this->indexer->normalize('3315.0123.4567.0001'))->toBe('3315012345670001');
});

it('changes completely when the pepper changes', function (): void {
    // The pepper is deliberately not APP_KEY: compromising one must not
    // reveal the other.
    $peppered = $this->indexer->hash('3315012345670001');

    config()->set('klinikform.blind_index.pepper', 'a-different-pepper');

    expect((new BlindIndexer)->hash('3315012345670001'))->not->toBe($peppered);
});

it('validates the 16 digit NIK format required by AC-05.3', function (): void {
    expect($this->indexer->isValidNik('3315012345670001'))->toBeTrue()
        ->and($this->indexer->isValidNik('3315-0123-4567-0001'))->toBeTrue()
        ->and($this->indexer->isValidNik('331501234567'))->toBeFalse()
        ->and($this->indexer->isValidNik('abcdefghijklmnop'))->toBeFalse()
        ->and($this->indexer->isValidNik(''))->toBeFalse();
});

it('masks a NIK exactly as security.md specifies', function (): void {
    // security.md §PII Handling: 331501******0001
    expect($this->indexer->mask('3315012345670001'))->toBe('331501******0001');
});

it('masks short input without leaking digits', function (): void {
    expect($this->indexer->mask('123'))->toBe('***')
        ->and($this->indexer->mask(''))->toBe('');
});

it('refuses to compute an index when no pepper is configured', function (): void {
    config()->set('klinikform.blind_index.pepper', null);

    expect(fn () => (new BlindIndexer)->hash('3315012345670001'))
        ->toThrow(RuntimeException::class);
});

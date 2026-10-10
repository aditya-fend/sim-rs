<?php

declare(strict_types=1);

use App\Services\Clinical\GcsCalculator;

/**
 * testing.md UT-03 — App\Services\Clinical\GcsCalculator.
 *
 * "Returns total sum = 12 and category string = 'apatis'."
 */
beforeEach(function (): void {
    $this->gcs = new GcsCalculator;
});

it('matches the AC-02.1 worked example exactly', function (): void {
    $result = $this->gcs->calculate(3, 4, 5);

    expect($result)->toBe([
        'eye' => 3,
        'verbal' => 4,
        'motor' => 5,
        'total' => 12,
        'category' => 'apatis',
    ]);
});

it('sums the three sub-scores', function (): void {
    expect($this->gcs->calculate(4, 5, 6)['total'])->toBe(15);
});

it('classifies every band dfd.md defines', function (string $expected, int $total): void {
    expect($this->gcs->categorize($total))->toBe($expected);
})->with([
    ['coma', 3],
    ['sopor', 4],
    ['sopor', 8],
    ['somnolent', 9],
    ['somnolent', 11],
    ['apatis', 12],
    ['apatis', 14],
    ['compos_mentis', 15],
]);

it('labels each band in Indonesian for the widget', function (): void {
    expect($this->gcs->label(3))->toBe('Coma')
        ->and($this->gcs->label(8))->toBe('Sopor')
        ->and($this->gcs->label(11))->toBe('Somnolent')
        ->and($this->gcs->label(12))->toBe('Apatis')
        ->and($this->gcs->label(15))->toBe('Compos Mentis');
});

it('reports an incomplete widget rather than a misleading total', function (): void {
    expect($this->gcs->isComplete(null))->toBeFalse()
        ->and($this->gcs->isComplete(['eye' => 3]))->toBeFalse()
        ->and($this->gcs->isComplete(['eye' => 3, 'verbal' => 4]))->toBeFalse()
        ->and($this->gcs->isComplete(['eye' => 3, 'verbal' => 4, 'motor' => 5]))->toBeTrue();
});

it('returns null totals while the widget is incomplete', function (): void {
    expect($this->gcs->evaluate(['eye' => 3, 'verbal' => 4]))
        ->toBe(['total' => null, 'category' => null, 'complete' => false]);

    expect($this->gcs->evaluate(['eye' => 3, 'verbal' => 4, 'motor' => 5]))
        ->toBe(['total' => 12, 'category' => 'apatis', 'complete' => true]);
});

it('accepts numeric strings, which is how form input arrives', function (): void {
    expect($this->gcs->evaluate(['eye' => '3', 'verbal' => '4', 'motor' => '5'])['total'])->toBe(12);
});

it('rejects sub-scores outside the clinical range', function (): void {
    expect(fn () => $this->gcs->calculate(0, 4, 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->gcs->calculate(5, 4, 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->gcs->calculate(3, 6, 5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->gcs->calculate(3, 4, 7))->toThrow(InvalidArgumentException::class);
});

it('rejects a total outside 3-15', function (): void {
    expect(fn () => $this->gcs->categorize(16))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->gcs->categorize(2))->toThrow(InvalidArgumentException::class);
});

it('names the component that was out of range', function (): void {
    expect(fn () => $this->gcs->calculate(3, 9, 5))
        ->toThrow(InvalidArgumentException::class, 'Verbal harus berada di antara 1 dan 5');
});

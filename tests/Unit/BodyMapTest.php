<?php

declare(strict_types=1);

use App\Services\Clinical\BodyMap;

/**
 * FR-06 — the pain map stores coordinates as percentages of the viewBox, so a
 * pin dropped on a ward tablet lands in the same anatomical place on a DPJP
 * workstation. That only holds if the geometry is exact, and the Blade
 * template draws its hit areas from this same table.
 */
it('maps every region id the schema declares to a box inside the viewBox', function (): void {
    $schema = json_decode(
        (string) file_get_contents(__DIR__.'/../../database/schemas/triage-ed-01.json'),
        true,
    );

    $declared = collect($schema['sections'])
        ->flatMap(fn (array $section): array => $section['fields'])
        ->filter(fn (array $field): bool => $field['type'] === 'pain_canvas')
        ->flatMap(fn (array $field): array => $field['regions'])
        ->pluck('id');

    expect($declared)->not->toBeEmpty();

    foreach ($declared as $id) {
        [$x, $y, $w, $h] = BodyMap::boxFor($id);

        expect($x)->toBeGreaterThanOrEqual(0.0)
            ->and($y)->toBeGreaterThanOrEqual(0.0)
            ->and($x + $w)->toBeLessThanOrEqual(100.0)
            ->and($y + $h)->toBeLessThanOrEqual(200.0)
            ->and(BodyMap::has($id))->toBeTrue();
    }
});

it('falls back to the torso for an unmapped region instead of breaking the view', function (): void {
    expect(BodyMap::has('not_a_region'))->toBeFalse()
        ->and(BodyMap::boxFor('not_a_region'))->toBe(BodyMap::boxFor('chest'));
});

it('resolves a click on the same box it draws', function (): void {
    // `back` covers the whole posterior torso and is drawn on the posterior
    // figure alone, so it is never the winner on a shared screen area; every
    // other region must resolve to itself from its own centre.
    foreach (array_diff(BodyMap::ids(), ['back']) as $id) {
        [$x, $y, $w, $h] = BodyMap::boxFor($id);

        expect(BodyMap::regionAt($x + ($w / 2), $y + ($h / 2)))->toBe($id);
    }
});

it('reports a miss outside every hit area', function (): void {
    // Far below the silhouette: legs end at y=158, the viewBox runs to 200.
    expect(BodyMap::regionAt(50.0, 195.0))->toBe('unknown');
});

it('includes a box edge as a hit rather than as a miss', function (): void {
    [$x, $y, $w, $h] = BodyMap::boxFor('head');

    expect(BodyMap::regionAt($x, $y))->toBe('head')
        ->and(BodyMap::regionAt($x + $w, $y + $h))->toBe('head');
});

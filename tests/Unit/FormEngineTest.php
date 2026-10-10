<?php

declare(strict_types=1);

use App\Services\Clinical\PainMarkerNormalizer;
use App\Services\FormEngine\ConditionalDisplay;
use App\Services\FormEngine\SchemaValidator;

/**
 * FR-03 conditional display, FR-06 pain coordinates, and the schema gate that
 * keeps a broken schema from ever going live.
 */
beforeEach(function (): void {
    $this->conditional = new ConditionalDisplay;
    $this->pain = new PainMarkerNormalizer;
});

/* -------------------------------------------------------------------------- */
/* FR-03 — conditional display */
/* -------------------------------------------------------------------------- */

it('shows a field with no condition', function (): void {
    expect($this->conditional->isVisible([], ['name' => 'systolic', 'conditional_display' => null]))->toBeTrue();
});

it('evaluates a numeric threshold the way a form input arrives it', function (): void {
    $field = [
        'name' => 'travel_endemic_area',
        'conditional_display' => ['depends_on' => 'body_temperature', 'operator' => '>=', 'value' => 38],
    ];

    // The schema holds 38 as a number; the request carries "38.5" as a string.
    expect($this->conditional->isVisible(['body_temperature' => '38.5'], $field))->toBeTrue()
        ->and($this->conditional->isVisible(['body_temperature' => '37.1'], $field))->toBeFalse()
        ->and($this->conditional->isVisible(['body_temperature' => 38], $field))->toBeTrue();
});

it('drives a field from patient context that is not itself a form field', function (): void {
    // FR-03: "Pregnancy Week" shows only for a female patient.
    $field = [
        'name' => 'pregnancy_week',
        'conditional_display' => ['depends_on' => 'gender', 'operator' => '==', 'value' => 'female'],
    ];

    expect($this->conditional->isVisible(['gender' => 'female'], $field))->toBeTrue()
        ->and($this->conditional->isVisible(['gender' => 'male'], $field))->toBeFalse();
});

it('combines multiple conditions with AND', function (): void {
    $field = [
        'name' => 'pregnancy_week',
        'conditional_display' => [
            ['depends_on' => 'gender', 'operator' => '==', 'value' => 'female'],
            ['depends_on' => 'age', 'operator' => 'between', 'value' => [10, 55]],
        ],
    ];

    // 'between' is not a supported operator; unsupported operators fail closed.
    expect($this->conditional->isVisible(['gender' => 'female', 'age' => 30], $field))->toBeFalse();
});

it('supports filled and blank for optional follow-up questions', function (): void {
    $field = ['conditional_display' => ['depends_on' => 'chief_complaint', 'operator' => 'filled']];

    expect($this->conditional->isVisible(['chief_complaint' => 'Nyeri'], $field))->toBeTrue()
        ->and($this->conditional->isVisible(['chief_complaint' => ''], $field))->toBeFalse();

    $blank = ['conditional_display' => ['depends_on' => 'allergy_history', 'operator' => 'blank']];

    expect($this->conditional->isVisible([], $blank))->toBeTrue()
        ->and($this->conditional->isVisible(['allergy_history' => 'Penicilin'], $blank))->toBeFalse();
});

it('supports in and not_in', function (): void {
    $field = ['conditional_display' => ['depends_on' => 'triage_category', 'operator' => 'in', 'value' => ['resuscitation', 'emergent']]];

    expect($this->conditional->isVisible(['triage_category' => 'emergent'], $field))->toBeTrue()
        ->and($this->conditional->isVisible(['triage_category' => 'non_urgent'], $field))->toBeFalse();
});

it('fails closed on a malformed condition rather than showing the field', function (): void {
    expect($this->conditional->isVisible([], ['conditional_display' => ['depends_on' => 'x', 'operator' => '???']]))->toBeFalse()
        ->and($this->conditional->isVisible([], ['conditional_display' => 'nonsense']))->toBeFalse();
});

it('prunes values belonging to fields that are currently hidden', function (): void {
    $fields = [
        ['name' => 'travel_endemic_area', 'conditional_display' => ['depends_on' => 'body_temperature', 'operator' => '>=', 'value' => 38]],
        ['name' => 'chief_complaint', 'conditional_display' => null],
    ];

    // Temperature dropped below the threshold: a stale "Ya" must not persist.
    $pruned = $this->conditional->prune([
        'body_temperature' => 37.0,
        'travel_endemic_area' => 'yes',
        'chief_complaint' => 'Nyeri ulu hati',
    ], $fields);

    expect($pruned)->not->toHaveKey('travel_endemic_area')
        ->and($pruned['chief_complaint'])->toBe('Nyeri ulu hati');
});

/* -------------------------------------------------------------------------- */
/* FR-06 — anatomical pain markers */
/* -------------------------------------------------------------------------- */

it('normalises a marker into the AC-03.2 shape', function (): void {
    $marker = $this->pain->normalize([
        'x' => 48.2,
        'y' => 32.5,
        'region' => 'epigastrium',
        'severity' => 7,
    ]);

    expect($marker)->toBe(['x' => 48.2, 'y' => 32.5, 'region' => 'epigastrium', 'severity' => 7]);
});

it('clamps coordinates into the 0-100 range', function (): void {
    $marker = $this->pain->normalize(['x' => 120.0, 'y' => -5.0, 'region' => 'chest', 'severity' => 3]);

    expect($marker['x'])->toBe(100.0)
        ->and($marker['y'])->toBe(0.0);
});

it('clamps the NRS severity into 0-10', function (): void {
    expect($this->pain->normalize(['x' => 1, 'y' => 1, 'region' => 'chest', 'severity' => 99])['severity'])->toBe(10)
        ->and($this->pain->normalize(['x' => 1, 'y' => 1, 'region' => 'chest', 'severity' => -4])['severity'])->toBe(0);
});

it('converts pointer pixels into normalised percentages', function (): void {
    // A click at 48.2% across a 200px-wide map is at 96.4px.
    expect($this->pain->fromPixels(96.4, 65.0, 200.0, 200.0))->toBe(['x' => 48.2, 'y' => 32.5]);
});

it('rejects a degenerate viewport rather than dividing by zero', function (): void {
    expect(fn () => $this->pain->fromPixels(10, 10, 0, 100))->toThrow(InvalidArgumentException::class);
});

it('requires x, y and region', function (): void {
    expect(fn () => $this->pain->normalize(['x' => 1, 'y' => 1]))->toThrow(InvalidArgumentException::class);
});

it('summarises a set of markers', function (): void {
    $markers = [
        ['x' => 10, 'y' => 10, 'region' => 'chest', 'severity' => 3],
        ['x' => 20, 'y' => 20, 'region' => 'epigastrium', 'severity' => 9],
    ];

    expect($this->pain->peakSeverity($markers))->toBe(9)
        ->and($this->pain->regions($markers))->toBe(['chest', 'epigastrium'])
        ->and($this->pain->severityBand(0))->toBe('Tidak Nyeri')
        ->and($this->pain->severityBand(3))->toBe('Ringan')
        ->and($this->pain->severityBand(6))->toBe('Sedang')
        ->and($this->pain->severityBand(10))->toBe('Berat');
});

/* -------------------------------------------------------------------------- */
/* FR-01 / FR-02 — schema validation */
/* -------------------------------------------------------------------------- */

it('accepts a minimal well-formed schema', function (): void {
    $definition = [
        'sections' => [[
            'key' => 'vitals',
            'label' => 'Tanda Vital',
            'fields' => [[
                'name' => 'systolic',
                'label' => 'Sistole',
                'type' => 'number',
                'validation_rules' => ['required', 'integer'],
            ]],
        ]],
    ];

    expect((new SchemaValidator)->isValid($definition))->toBeTrue();
});

it('rejects a schema with no sections', function (): void {
    expect((new SchemaValidator)->validate(['sections' => []]))->not->toBeEmpty();
});

it('rejects duplicate field names, which would silently collide in the payload', function (): void {
    $definition = [
        'sections' => [[
            'key' => 'a',
            'fields' => [
                ['name' => 'systolic', 'label' => 'A', 'type' => 'number'],
                ['name' => 'systolic', 'label' => 'B', 'type' => 'number'],
            ],
        ]],
    ];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('duplikat');
});

it('rejects an unknown field type instead of rendering nothing', function (): void {
    $definition = ['sections' => [['key' => 'a', 'fields' => [
        ['name' => 'x', 'label' => 'X', 'type' => 'hologram'],
    ]]]];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('tidak dikenali');
});

it('requires options on a select field', function (): void {
    $definition = ['sections' => [['key' => 'a', 'fields' => [
        ['name' => 'x', 'label' => 'X', 'type' => 'select'],
    ]]]];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('options');
});

it('rejects a validation rule the engine cannot execute', function (): void {
    $definition = ['sections' => [['key' => 'a', 'fields' => [
        ['name' => 'x', 'label' => 'X', 'type' => 'number', 'validation_rules' => ['telepathy']],
    ]]]];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('tidak didukung');
});

it('rejects an unsupported conditional operator', function (): void {
    $definition = ['sections' => [['key' => 'a', 'fields' => [
        [
            'name' => 'x',
            'label' => 'X',
            'type' => 'text',
            'conditional_display' => ['depends_on' => 'y', 'operator' => 'approximately', 'value' => 1],
        ],
    ]]]];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('operator');
});

it('requires a value on a comparison operator', function (): void {
    $definition = ['sections' => [['key' => 'a', 'fields' => [
        [
            'name' => 'x',
            'label' => 'X',
            'type' => 'text',
            'conditional_display' => ['depends_on' => 'y', 'operator' => '>='],
        ],
    ]]]];

    expect(implode(' ', (new SchemaValidator)->validate($definition)))->toContain('value');
});

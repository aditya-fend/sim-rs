<?php

declare(strict_types=1);

use App\Services\Clinical\DosageCalculator;
use App\Services\FormEngine\SchemaValidator;

/**
 * FR-07 / AC-02.2 — pediatric dose computation, and the schema rules that
 * feed it. The rule set is read from the shipped schema so a drift between the
 * seeded form and the calculator shows up here rather than on the ward.
 */
beforeEach(function (): void {
    $this->calculator = new DosageCalculator;

    $schema = json_decode(
        (string) file_get_contents(base_path('database/schemas/assess-inpat-01.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    foreach ($schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            if (($field['name'] ?? null) === 'pediatric_dosage') {
                $this->field = $field;
            }
        }
    }

    $this->rules = $this->field['rules'];
});

it('computes the AC-02.2 amoxicillin dose for a 14 kg child', function (): void {
    $result = $this->calculator->calculate($this->rules, 14.0, 'Amoksisilin');

    // 14 kg x 25 mg/kg = 350 mg per dose.
    expect($result['dose_mg'])->toBe(350.0)
        ->and($result['doses_per_day'])->toBe(3)
        ->and($result['dose_per_day_mg'])->toBe(1050.0)
        ->and($result['max_daily_mg'])->toBe(1400.0)
        ->and($result['exceeds_max'])->toBeFalse();
});

it('looks a drug up by its generic name as well as its trade name', function (): void {
    expect($this->calculator->calculate($this->rules, 14.0, 'Amoxicillin')['dose_mg'])->toBe(350.0);
});

it('scales linearly with body weight', function (): void {
    expect($this->calculator->calculate($this->rules, 20.0, 'Amoksisilin')['dose_mg'])->toBe(500.0);
});

it('flags a computed dose that breaches the daily ceiling', function (): void {
    // Azithromycin: 10 mg/kg/dose x 1/day, ceiling 15 mg/kg/day.
    $normal = $this->calculator->calculate($this->rules, 10.0, 'Azitromisin');
    expect($normal['exceeds_max'])->toBeFalse();

    config()->set('klinikform.placeholder', null);
    $rules = $this->rules;
    foreach ($rules as &$rule) {
        if ($rule['drug'] === 'Azitromisin') {
            // Force the over-maximum condition the warning exists to catch.
            $rule['mg_per_kg_per_dose'] = 20;
        }
    }

    expect($this->calculator->calculate($rules, 10.0, 'Azitromisin')['exceeds_max'])->toBeTrue();
});

it('records a DPJP override together with its justification', function (): void {
    $computed = $this->calculator->calculate($this->rules, 14.0, 'Amoksisilin');

    $overridden = $this->calculator->override($computed, 500.0, 'Infeksi resisten, perlu dosis lebih tinggi.');

    expect($overridden['dose_mg'])->toBe(500.0)
        ->and($overridden['override_reason'])->toBe('Infeksi resisten, perlu dosis lebih tinggi.')
        ->and($overridden['is_override'])->toBeTrue();
});

it('leaves the computed dose untouched when no override is given', function (): void {
    $computed = $this->calculator->calculate($this->rules, 14.0, 'Amoksisilin');

    expect($this->calculator->override($computed, null, null))->toBe($computed);
});

it('rejects a drug the form does not carry a rule for', function (): void {
    expect(fn () => $this->calculator->calculate($this->rules, 14.0, 'Gentamicin'))
        ->toThrow(InvalidArgumentException::class, 'Tidak ada aturan dosis');
});

it('rejects a non-positive weight', function (): void {
    expect(fn () => $this->calculator->calculate($this->rules, 0.0, 'Amoksisilin'))
        ->toThrow(InvalidArgumentException::class);
});

it('lists the drug names available on the form', function (): void {
    expect($this->calculator->drugNames($this->rules))
        ->toBe(['Amoksisilin', 'Paracetamol', 'Ibuprofen', 'Seftotaksim', 'Azitromisin'])
        ->and($this->calculator->hasRule($this->rules, 'Paracetamol'))->toBeTrue()
        ->and($this->calculator->hasRule($this->rules, 'Gentamicin'))->toBeFalse();
});

it('ships a schema that passes its own validator', function (): void {
    // A seed schema that fails validation would block intake on deploy.
    foreach (['triage-ed-01.json', 'assess-inpat-01.json'] as $file) {
        $definition = json_decode(
            (string) file_get_contents(base_path('database/schemas/'.$file)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect((new SchemaValidator)->validate($definition))->toBe([]);
    }
});

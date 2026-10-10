<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use InvalidArgumentException;

/**
 * Weight-based pediatric dose calculator (FR-07, AC-02.2).
 *
 * Dosing rules are not a table of their own — they live inside the form
 * template's `schema_definition`, because FR-01 makes the schema the single
 * source of metadata for a clinical form and a hospital must be able to amend
 * its own protocols without a database migration.
 *
 * The model is uniform across drugs:
 *
 *   dose per administration = weight_kg x mg_per_kg_per_dose
 *   dose per day           = dose per administration x doses_per_day
 *   maximum per day        = weight_kg x max_mg_per_kg_per_day
 *
 * so AC-02.2 (14 kg amoxicillin at 25 mg/kg) yields 350 mg per dose, 1050 mg
 * per day, against a 1400 mg daily ceiling.
 */
final class DosageCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $rules  The schema's `rules` array.
     * @return array{drug: string, generic: string, route: string, weight_kg: float, dose_mg: float, doses_per_day: int, dose_per_day_mg: float, max_daily_mg: float, exceeds_max: bool}
     */
    public function calculate(array $rules, float $weightKg, string $drug): array
    {
        if ($weightKg <= 0) {
            throw new InvalidArgumentException('Berat badan harus lebih besar dari 0 kg.');
        }

        $rule = $this->ruleFor($rules, $drug);

        $mgPerKgPerDose = (float) $rule['mg_per_kg_per_dose'];
        $dosesPerDay = (int) $rule['doses_per_day'];
        $maxMgPerKgPerDay = (float) ($rule['max_mg_per_kg_per_day'] ?? 0.0);

        $dosePerAdministration = $weightKg * $mgPerKgPerDose;
        $dosePerDay = $dosePerAdministration * $dosesPerDay;
        $maxPerDay = $weightKg * $maxMgPerKgPerDay;

        return [
            'drug' => (string) $rule['drug'],
            'generic' => (string) ($rule['generic'] ?? $rule['drug']),
            'route' => (string) ($rule['route'] ?? 'Oral'),
            'weight_kg' => $weightKg,
            'dose_mg' => $this->round($dosePerAdministration),
            'doses_per_day' => $dosesPerDay,
            'dose_per_day_mg' => $this->round($dosePerDay),
            'max_daily_mg' => $this->round($maxPerDay),
            // FR-07: warn when the computed day total breaches the
            // Indonesian Pharmacopeia ceiling for that drug.
            'exceeds_max' => $maxPerDay > 0 && $dosePerDay > $maxPerDay,
        ];
    }

    /**
     * AC-02.2 requires the DPJP to be able to override a computed dose and
     * record why. Returns the override payload the form then persists.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function override(array $result, ?float $doseMg, ?string $reason): array
    {
        if ($doseMg === null) {
            return $result;
        }

        return [
            ...$result,
            'dose_mg' => $this->round($doseMg),
            'override_reason' => $reason,
            'is_override' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function hasRule(array $rules, string $drug): bool
    {
        return $this->findRule($rules, $drug) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return list<string>
     */
    public function drugNames(array $rules): array
    {
        $names = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule['drug'])) {
                $names[] = (string) $rule['drug'];
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function ruleFor(array $rules, string $drug): array
    {
        $rule = $this->findRule($rules, $drug);

        if ($rule === null) {
            throw new InvalidArgumentException(
                "Tidak ada aturan dosis untuk obat '{$drug}' pada formulir ini."
            );
        }

        return $rule;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function findRule(array $rules, string $drug): ?array
    {
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $name = (string) ($rule['drug'] ?? '');
            $generic = (string) ($rule['generic'] ?? '');

            if (strcasecmp($name, $drug) === 0 || strcasecmp($generic, $drug) === 0) {
                return $rule;
            }
        }

        return null;
    }

    /** Round to 0.01 mg — more precision is meaningless for a bedside dose. */
    private function round(float $value): float
    {
        return round($value, 2);
    }
}

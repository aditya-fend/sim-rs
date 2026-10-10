<?php

declare(strict_types=1);

namespace App\Services\FormEngine;

/**
 * Evaluates `conditional_display` rules from a form schema (FR-03).
 *
 * The same logic exists twice on purpose: here for server-side validation
 * (a hidden field must not be required) and in resources/js/form-engine.js
 * for live show/hide. The JS mirror is asserted against this class by
 * tests/Unit/ConditionalDisplayTest.php so the two cannot drift.
 *
 * A condition is `{depends_on, operator, value}`; a field may carry a list of
 * them, combined with AND.
 */
final class ConditionalDisplay
{
    /**
     * @param  array<string, mixed>  $payload  Current form values.
     * @param  array<string, mixed>  $field  Field definition.
     */
    public function isVisible(array $payload, array $field): bool
    {
        $condition = $field['conditional_display'] ?? null;

        if ($condition === null || $condition === []) {
            return true;
        }

        $conditions = array_is_list($condition) ? $condition : [$condition];

        foreach ($conditions as $single) {
            if (! is_array($single) || ! $this->evaluate($payload, $single)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fields whose conditions are not met must not be validated, otherwise a
     * hidden "Riwayat Perjalanan Daerah Endemis" would still fail `required`.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $field
     */
    public function shouldValidate(array $payload, array $field): bool
    {
        return $this->isVisible($payload, $field);
    }

    /**
     * Drop values belonging to fields that are currently hidden, so a stale
     * answer cannot ride along in the stored payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function prune(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;

            if (! is_string($name) || ($field['conditional_display'] ?? null) === null) {
                continue;
            }

            if (! $this->isVisible($payload, $field)) {
                unset($payload[$name]);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    public function evaluate(array $payload, array $condition): bool
    {
        $field = $condition['depends_on'] ?? null;
        $operator = $condition['operator'] ?? null;
        $expected = $condition['value'] ?? null;

        if (! is_string($field) || ! is_string($operator)) {
            return false;
        }

        $actual = $payload[$field] ?? null;

        return match ($operator) {
            'filled' => $actual !== null && $actual !== '' && $actual !== [],
            'blank' => $actual === null || $actual === '' || $actual === [],
            '==' => $this->looseEquals($actual, $expected),
            '!=' => ! $this->looseEquals($actual, $expected),
            '>' => $this->numericCompare($actual, $expected, static fn ($a, $b): bool => $a > $b),
            '>=' => $this->numericCompare($actual, $expected, static fn ($a, $b): bool => $a >= $b),
            '<' => $this->numericCompare($actual, $expected, static fn ($a, $b): bool => $a < $b),
            '<=' => $this->numericCompare($actual, $expected, static fn ($a, $b): bool => $a <= $b),
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, false),
            default => false,
        };
    }

    /**
     * Form input arrives as strings ("85" from a text input) while a schema
     * threshold is written as a number (38.0). Comparing them strictly would
     * make every numeric condition silently false.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    /**
     * @param  callable(float, float): bool  $compare
     */
    private function numericCompare(mixed $actual, mixed $expected, callable $compare): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return $compare((float) $actual, (float) $expected);
    }
}

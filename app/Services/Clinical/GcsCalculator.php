<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use InvalidArgumentException;

/**
 * Glasgow Coma Scale (FR-05, UT-03).
 *
 * Bands follow dfd.md §P4.0, which is the only document that states a complete,
 * non-overlapping set:
 *
 *   3          Coma
 *   4 – 8      Sopor
 *   9 – 11     Somnolent
 *   12 – 14    Apatis
 *   15         Compos Mentis
 *
 * AC-02.1 calls total 12 "Moderate Impairment", the English clinical name for
 * the same band. The stored value is "apatis" — ui-spec.md, dfd.md, api-spec.md
 * and testing.md's UT-03 all agree on that token, and the UI renders the
 * Indonesian label alongside the English gloss.
 */
final class GcsCalculator
{
    /** Valid sub-score ranges. */
    public const EYE_RANGE = [1, 4];

    public const VERBAL_RANGE = [1, 5];

    public const MOTOR_RANGE = [1, 6];

    public const TOTAL_RANGE = [3, 15];

    /**
     * @return array{eye: int, verbal: int, motor: int, total: int, category: string}
     */
    public function calculate(int $eye, int $verbal, int $motor): array
    {
        $this->assertInRange('Eye', $eye, self::EYE_RANGE);
        $this->assertInRange('Verbal', $verbal, self::VERBAL_RANGE);
        $this->assertInRange('Motor', $motor, self::MOTOR_RANGE);

        $total = $eye + $verbal + $motor;

        return [
            'eye' => $eye,
            'verbal' => $verbal,
            'motor' => $motor,
            'total' => $total,
            'category' => $this->categorize($total),
        ];
    }

    public function categorize(int $total): string
    {
        $this->assertInRange('Total GCS', $total, self::TOTAL_RANGE);

        return match (true) {
            $total === 3 => 'coma',
            $total <= 8 => 'sopor',
            $total <= 11 => 'somnolent',
            $total <= 14 => 'apatis',
            default => 'compos_mentis',
        };
    }

    /** Indonesian label, as used on the GCS widget. */
    public function label(int $total): string
    {
        return match ($this->categorize($total)) {
            'coma' => 'Coma',
            'sopor' => 'Sopor',
            'somnolent' => 'Somnolent',
            'apatis' => 'Apatis',
            default => 'Compos Mentis',
        };
    }

    /**
     * True when every component is present. The widget shows the "choose all
     * three" hint until this passes (ui-spec.md `c-widget-gcs` `incomplete`).
     *
     * @param  array<string, mixed>|null  $parts
     */
    public function isComplete(?array $parts): bool
    {
        if ($parts === null) {
            return false;
        }

        foreach (['eye', 'verbal', 'motor'] as $key) {
            if (! isset($parts[$key]) || ! is_numeric($parts[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirrors the server calculation for a partially filled widget so the
     * client can show "incomplete" rather than a misleading total.
     *
     * @param  array<string, mixed>  $parts
     * @return array{total: int|null, category: string|null, complete: bool}
     */
    public function evaluate(array $parts): array
    {
        if (! $this->isComplete($parts)) {
            return ['total' => null, 'category' => null, 'complete' => false];
        }

        $result = $this->calculate((int) $parts['eye'], (int) $parts['verbal'], (int) $parts['motor']);

        return [
            'total' => $result['total'],
            'category' => $result['category'],
            'complete' => true,
        ];
    }

    /**
     * @param  array{0: int, 1: int}  $range
     */
    private function assertInRange(string $label, int $value, array $range): void
    {
        if ($value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException(
                "{$label} harus berada di antara {$range[0]} dan {$range[1]}, diberikan {$value}."
            );
        }
    }
}

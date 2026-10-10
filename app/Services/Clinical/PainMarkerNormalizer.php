<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use InvalidArgumentException;

/**
 * Normalises anatomical pain markers (FR-06, AC-03.2).
 *
 * Coordinates are stored as percentages of the body-map viewport rather than
 * pixels, so a marker dropped on a 10" ward tablet lands in the same place on
 * a 1920px DPJP workstation. Values are clamped to [0, 100] — user-flow.md
 * §"Form Validation Boundary Violations" requires out-of-bounds input to be
 * clamped client-side and validated server-side.
 */
final class PainMarkerNormalizer
{
    public const MIN_SEVERITY = 0;

    public const MAX_SEVERITY = 10;

    /**
     * @param  array<int, mixed>  $markers
     * @return list<array{x: float, y: float, region: string, severity: int}>
     */
    public function normalizeMany(array $markers): array
    {
        $normalized = [];

        foreach ($markers as $marker) {
            if (is_array($marker)) {
                $normalized[] = $this->normalize($marker);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $marker
     * @return array{x: float, y: float, region: string, severity: int}
     */
    public function normalize(array $marker): array
    {
        if (! isset($marker['x'], $marker['y'], $marker['region'])) {
            throw new InvalidArgumentException('Penanda nyeri wajib menyertakan x, y, dan region.');
        }

        $severity = $marker['severity'] ?? 0;

        return [
            'x' => $this->clampCoordinate((float) $marker['x']),
            'y' => $this->clampCoordinate((float) $marker['y']),
            'region' => (string) $marker['region'],
            'severity' => $this->clampSeverity((int) $severity),
        ];
    }

    /**
     * Map a pointer event onto the body map. Kept server-side so the same
     * arithmetic can be asserted in a test rather than only in a browser.
     *
     * @return array{x: float, y: float}
     */
    public function fromPixels(float $clientX, float $clientY, float $elementWidth, float $elementHeight): array
    {
        if ($elementWidth <= 0 || $elementHeight <= 0) {
            throw new InvalidArgumentException('Dimensi peta tubuh tidak valid.');
        }

        return [
            'x' => $this->clampCoordinate($clientX / $elementWidth * 100),
            'y' => $this->clampCoordinate($clientY / $elementHeight * 100),
        ];
    }

    /**
     * Highest severity recorded, used for the patient strip's pain summary.
     *
     * @param  list<array<string, mixed>>  $markers
     */
    public function peakSeverity(array $markers): int
    {
        $peak = 0;

        foreach ($markers as $marker) {
            $severity = (int) ($marker['severity'] ?? 0);
            $peak = max($peak, $severity);
        }

        return $peak;
    }

    /**
     * @param  list<array<string, mixed>>  $markers
     * @return list<string>
     */
    public function regions(array $markers): array
    {
        $regions = [];

        foreach ($markers as $marker) {
            $region = (string) ($marker['region'] ?? '');

            if ($region !== '' && ! in_array($region, $regions, true)) {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    /** ui-spec.md `pain.modal.helper`: 0 none, 1–3 mild, 4–6 moderate, 7–10 severe. */
    public function severityBand(int $severity): string
    {
        return match (true) {
            $severity === 0 => 'Tidak Nyeri',
            $severity <= 3 => 'Ringan',
            $severity <= 6 => 'Sedang',
            default => 'Berat',
        };
    }

    private function clampCoordinate(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    private function clampSeverity(int $severity): int
    {
        return max(self::MIN_SEVERITY, min(self::MAX_SEVERITY, $severity));
    }
}

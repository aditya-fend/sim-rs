<?php

declare(strict_types=1);

namespace App\Services\Clinical;

/**
 * Anatomical region geometry for the body map (FR-06).
 *
 * The SVG is a simplified anterior/posterior silhouette; this maps a region id
 * to the rectangle that represents it, in the same 0-100 x 0-200 viewBox the
 * template draws. Keeping the geometry here rather than in Blade means the hit
 * areas, the region list in the schema, and the stored `region` value cannot
 * drift apart.
 */
final class BodyMap
{
    /**
     * Region id => [x, y, width, height].
     *
     * The silhouette spans y=7..158 for a 100x200 viewBox: head at the top,
     * torso through the middle, legs below.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const REGIONS = [
        'head' => [41.0, 8.0, 18.0, 20.0],
        'neck' => [43.0, 28.0, 14.0, 10.0],
        'chest' => [37.0, 38.0, 26.0, 26.0],
        'epigastrium' => [40.0, 64.0, 20.0, 14.0],
        'right_upper_quadrant' => [37.0, 64.0, 15.0, 16.0],
        'left_upper_quadrant' => [48.0, 64.0, 15.0, 16.0],
        'periumbilical' => [42.0, 78.0, 16.0, 14.0],
        'right_lower_quadrant' => [37.0, 78.0, 15.0, 16.0],
        'left_lower_quadrant' => [48.0, 78.0, 15.0, 16.0],
        'back' => [37.0, 38.0, 26.0, 60.0],
        'right_arm' => [22.0, 40.0, 14.0, 58.0],
        'left_arm' => [64.0, 40.0, 14.0, 58.0],
        'right_leg' => [38.0, 100.0, 12.0, 58.0],
        'left_leg' => [50.0, 100.0, 12.0, 58.0],
    ];

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function boxFor(string $regionId): array
    {
        // An unmapped region still needs a drawable box rather than a broken
        // view; the torso is the most defensible fallback.
        return self::REGIONS[$regionId] ?? self::REGIONS['chest'];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::REGIONS);
    }

    /**
     * Which region a click at these viewBox percentages landed in.
     */
    public static function regionAt(float $x, float $y): string
    {
        foreach (self::REGIONS as $id => [$bx, $by, $bw, $bh]) {
            if ($x >= $bx && $x <= $bx + $bw && $y >= $by && $y <= $by + $bh) {
                return $id;
            }
        }

        return 'unknown';
    }

    public static function has(string $regionId): bool
    {
        return isset(self::REGIONS[$regionId]);
    }
}

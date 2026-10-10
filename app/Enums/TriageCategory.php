<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ATS triage priority, FR-04. Stored values match erd.md
 * `form_submissions.triage_category` and ui-spec.md `c-patient-strip`.
 */
enum TriageCategory: string
{
    case Resuscitation = 'resuscitation';
    case Emergent = 'emergent';
    case Urgent = 'urgent';
    case LessUrgent = 'less_urgent';
    case NonUrgent = 'non_urgent';

    /**
     * ui-spec.md copy_and_microcopy. Colour is never the sole signal — the
     * label always ships alongside the pill.
     *
     * @return array{label: string, css: string, code: string}
     */
    public function presentation(): array
    {
        return match ($this) {
            self::Resuscitation => ['label' => 'Kategori 1: Resusitasi (Merah)', 'css' => 'triage-resus', 'code' => 'P1'],
            self::Emergent => ['label' => 'Kategori 2: Emergensi (Oranye)', 'css' => 'triage-emergent', 'code' => 'P2'],
            self::Urgent => ['label' => 'Kategori 3: Urgensi (Kuning)', 'css' => 'triage-urgent', 'code' => 'P3'],
            self::LessUrgent => ['label' => 'Kategori 4: Semi-Urgensi (Hijau)', 'css' => 'triage-lessurgent', 'code' => 'P4'],
            self::NonUrgent => ['label' => 'Kategori 5: Non-Urgensi (Biru)', 'css' => 'triage-nonurgent', 'code' => 'P5'],
        };
    }

    /** Minutes a patient may safely wait, per the Indonesian ATS standard. */
    public function targetMinutes(): int
    {
        return match ($this) {
            self::Resuscitation => 0,
            self::Emergent => 10,
            self::Urgent => 30,
            self::LessUrgent => 60,
            self::NonUrgent => 120,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->presentation()['label'];
        }

        return $out;
    }
}

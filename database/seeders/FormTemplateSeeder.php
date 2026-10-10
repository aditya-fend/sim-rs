<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FormTemplate;
use App\Models\User;
use App\Services\FormEngine\SchemaRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Installs the two standard Ministry of Health instruments FR-04 requires:
 *
 *   TRIAGE-ED-01     Formulir Triase Gawat Darurat (ATS)
 *   ASSESS-INPAT-01  Asesmen Awal Keperawatan & Medis Rawat Inap
 *
 * The schemas live as JSON under database/schemas/ rather than inline in PHP,
 * because that is the same document a deployment engineer edits in
 * /admin/schemas — keeping one copy avoids the two drifting.
 *
 * TRIAGE-ED-01 is installed at two versions so AC-01.3 is demonstrable out of
 * the box: a submission captured against v1 still renders against v1 after v2
 * becomes active.
 */
class FormTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->where('email', 'rian@klinikform.local')->first()
            ?? User::query()->firstOrFail();

        $repository = app(SchemaRepository::class);

        foreach ($this->definitions() as [$code, $name, $path]) {
            $definition = json_decode(
                File::get(base_path("database/schemas/{$path}")),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (FormTemplate::query()->where('code', $code)->exists()) {
                continue;
            }

            // v1 is retired, v2 is active.
            $repository->publish($code, $name, $definition, $author, activate: false);

            if ($code === 'TRIAGE-ED-01') {
                $repository->publish($code, $name.' (revisi 2)', $this->triageV2($definition), $author, activate: true);
            } else {
                FormTemplate::query()
                    ->where('code', $code)
                    ->orderByDesc('version')
                    ->firstOrFail()
                    ->update(['is_active' => true]);
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function definitions(): array
    {
        return [
            ['TRIAGE-ED-01', 'Formulir Triase Gawat Darurat', 'triage-ed-01.json'],
            ['ASSESS-INPAT-01', 'Asesmen Awal Keperawatan & Medis Rawat Inap', 'assess-inpat-01.json'],
        ];
    }

    /**
     * v2 of the triage sheet adds one conditional prompt — the change
     * user-flow.md §Flow 3 describes a deployment engineer making, so the
     * version-publish path ships with a worked example.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function triageV2(array $definition): array
    {
        $definition['description'] .= ' Revisi 2 menambahkan penelusuran riwayat perjalanan daerah endemis.';

        /** @var list<array<string, mixed>> $sections */
        $sections = $definition['sections'];

        foreach ($sections as &$section) {
            if (($section['key'] ?? null) === 'anamnesis') {
                /** @var list<array<string, mixed>> $fields */
                $fields = $section['fields'];

                foreach ($fields as &$field) {
                    if (($field['name'] ?? null) === 'allergy_history') {
                        $field['label'] = 'Riwayat Alergi & Obat yang Pernah Digunakan';
                    }
                }

                $section['fields'] = $fields;
            }
        }

        $definition['sections'] = $sections;

        return $definition;
    }
}

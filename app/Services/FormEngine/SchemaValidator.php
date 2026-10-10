<?php

declare(strict_types=1);

namespace App\Services\FormEngine;

use App\Models\FormTemplate;

/**
 * Structural validation of a candidate `schema_definition` (FR-01, FR-02).
 *
 * Runs before a version is published so a broken schema can never become the
 * active one and break intake at the ward. user-flow.md §Flow 3 promises the
 * admin editor "0 Error, N Field Baru Terdeteksi" before publishing — this is
 * what produces that count.
 */
final class SchemaValidator
{
    /** Field types the renderer knows how to draw. */
    public const FIELD_TYPES = [
        'text',
        'textarea',
        'number',
        'date',
        'datetime',
        'select',
        'radio',
        'checkbox',
        'gcs_calculator',
        'pain_canvas',
        'dosage_calculator',
    ];

    /** Comparison operators usable in a `conditional_display` block. */
    public const OPERATORS = ['==', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'filled', 'blank'];

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> Human-readable problems; empty means valid.
     */
    public function validate(array $definition): array
    {
        $errors = [];

        $sections = $definition['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return ['Schema harus memiliki minimal satu section.'];
        }

        $seen = [];

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                $errors[] = "Section #{$index} bukan objek yang valid.";

                continue;
            }

            $key = $section['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $errors[] = "Section #{$index} wajib memiliki 'key'.";

                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = "Section key '{$key}' duplikat.";
            }
            $seen[$key] = true;

            foreach ($this->validateFields(is_array($section['fields'] ?? null) ? $section['fields'] : [], $key, $seen) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $definition */
    public function isValid(array $definition): bool
    {
        return $this->validate($definition) === [];
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  array<string, bool>  $seen
     * @return list<string>
     */
    private function validateFields(array $fields, string $sectionKey, array &$seen): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                $errors[] = "Field pada section '{$sectionKey}' bukan objek yang valid.";

                continue;
            }

            $name = $field['name'] ?? null;

            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                $errors[] = "Field pada section '{$sectionKey}' harus punya 'name' snake_case yang valid.";

                continue;
            }

            if (isset($seen[$name])) {
                $errors[] = "Field '{$name}' duplikat pada schema ini.";
            }
            $seen[$name] = true;

            $label = $field['label'] ?? null;
            if (! is_string($label) || trim($label) === '') {
                $errors[] = "Field '{$name}' wajib memiliki 'label'.";
            }

            $type = $field['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = "Field '{$name}' punya tipe '{$type}' yang tidak dikenali. Gunakan salah satu: ".implode(', ', self::FIELD_TYPES).'.';

                continue;
            }

            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $options = $field['options'] ?? null;
                if (! is_array($options) || $options === []) {
                    $errors[] = "Field '{$name}' bertipe '{$type}' wajib memiliki 'options'.";
                }
            }

            foreach ($this->validateRules($name, $field['validation_rules'] ?? []) as $error) {
                $errors[] = $error;
            }

            foreach ($this->validateConditions($name, $field['conditional_display'] ?? null) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateRules(string $name, mixed $rules): array
    {
        if (! is_array($rules)) {
            return ["Field '{$name}': 'validation_rules' harus berupa array."];
        }

        $errors = [];

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                $errors[] = "Field '{$name}': aturan validasi harus berupa string.";

                continue;
            }

            $head = explode(':', $rule)[0];

            if (! in_array($head, self::allowedRules(), true)) {
                $errors[] = "Field '{$name}': aturan '{$head}' tidak didukung oleh mesin validasi.";
            }
        }

        return $errors;
    }

    /**
     * `conditional_display` is either a single condition or a list of them,
     * combined with AND (FR-03).
     *
     * @return list<string>
     */
    private function validateConditions(string $name, mixed $condition): array
    {
        if ($condition === null) {
            return [];
        }

        $conditions = array_is_list($condition) ? $condition : [$condition];
        $errors = [];

        foreach ($conditions as $single) {
            if (! is_array($single)) {
                $errors[] = "Field '{$name}': 'conditional_display' harus berupa objek kondisi.";

                continue;
            }

            $dependsOn = $single['depends_on'] ?? null;
            $operator = $single['operator'] ?? null;

            if (! is_string($dependsOn) || $dependsOn === '') {
                $errors[] = "Field '{$name}': kondisi harus menyebut 'depends_on'.";

                continue;
            }

            if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
                $errors[] = "Field '{$name}': operator '{$operator}' tidak didukung. Gunakan: ".implode(', ', self::OPERATORS).'.';

                continue;
            }

            if (! in_array($operator, ['filled', 'blank'], true) && ! array_key_exists('value', $single)) {
                $errors[] = "Field '{$name}': kondisi dengan operator '{$operator}' wajib menyertakan 'value'.";
            }
        }

        return $errors;
    }

    /**
     * The subset of Laravel rules the dynamic validator will accept. Anything
     * outside this list is rejected at publish time rather than silently
     * failing at validation time on the ward.
     *
     * @return list<string>
     */
    private static function allowedRules(): array
    {
        return [
            'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'boolean',
            'array', 'date', 'date_format', 'email', 'url', 'digits', 'digits_between',
            'min', 'max', 'in', 'not_in', 'between', 'regex', 'not_regex', 'same',
            'different', 'accepted', 'confirmed', 'gt', 'gte', 'lt', 'lte',
            'required_if', 'required_with', 'prohibited_if',
        ];
    }

    /**
     * Render a definition back to the JSON text stored in the editor.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function toJson(array $definition): string
    {
        return (string) json_encode(
            $definition,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromJson(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];

        return $decoded;
    }

    /** @return list<string> */
    public function validateTemplate(FormTemplate $template): array
    {
        return $this->validate($template->schema_definition);
    }
}

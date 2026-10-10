<?php

declare(strict_types=1);

namespace App\Services\FormEngine;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Builds and runs a Laravel validator from a form schema at runtime.
 *
 * No JSON Schema library is involved: FR-01 defines `validation_rules` as an
 * array of rule strings, and those compose straight into a Validator. The one
 * piece of intelligence added here is deriving `in:` from a field's `options`
 * so the schema author does not have to repeat the enum, and dropping rules
 * for fields whose `conditional_display` is currently false.
 */
final class DynamicValidator
{
    public function __construct(
        private readonly ConditionalDisplay $conditional = new ConditionalDisplay,
    ) {}

    /**
     * `$context` carries patient attributes (gender, age) that a
     * `conditional_display` rule may reference but that are not themselves form
     * fields — FR-03's "show Pregnancy Week only when gender is Female and age
     * is between 10 and 55". Context is used for condition evaluation only and
     * is never written into the stored payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function validate(FormTemplate $template, array $payload, array $context = []): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $payload,
            $this->rulesFor($template, $payload, $context),
            $this->messagesFor($template),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function passes(FormTemplate $template, array $payload, array $context = []): bool
    {
        return $this->validate($template, $payload, $context)->passes();
    }

    /**
     * Expose the ruleset so the client can be told the boundaries up front and
     * IT-01 / AC-01.2 can be asserted directly.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(FormTemplate $template, array $payload = [], array $context = []): array
    {
        $rules = [];
        $visible = array_merge($context, $payload);

        foreach ($template->fieldsByName() as $field) {
            if (! $this->conditional->shouldValidate($visible, $field)) {
                continue;
            }

            // rulesForField() is already keyed by the final rule name, so the
            // widget sub-rules land alongside the field's own rules.
            $rules += $this->rulesForField($field);
        }

        return $rules;
    }

    /**
     * Messages come from the schema so clinical wording lives with the form,
     * not in a translation file that would drift from it.
     *
     * A field declares one boundary sentence — "Nilai sistol harus di antara
     * 40 – 300 mmHg." — but expresses it as two rules (`min:40`, `max:300`).
     * Laravel would look for `systolic.min` and `systolic.max`, so a
     * `messages.between` entry is applied to both.
     *
     * @return array<string, string>
     */
    public function messagesFor(FormTemplate $template): array
    {
        $messages = [];

        foreach ($template->fields() as $field) {
            $name = $field['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            /** @var array<string, string> $fieldMessages */
            $fieldMessages = is_array($field['messages'] ?? null) ? $field['messages'] : [];

            foreach ($fieldMessages as $rule => $message) {
                $messages["{$name}.{$rule}"] = $message;
            }

            if (isset($fieldMessages['between'])) {
                $messages["{$name}.min"] = $fieldMessages['between'];
                $messages["{$name}.max"] = $fieldMessages['between'];
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function rulesForField(array $field): array
    {
        $name = (string) ($field['name'] ?? '');
        $rules = [];

        /** @var array<int, mixed> $own */
        $own = is_array($field['validation_rules'] ?? null) ? $field['validation_rules'] : [];
        $own = [...$own, ...$this->derivedTypeRules($field)];

        if ($own !== []) {
            $rules[$name] = $own;
        }

        foreach ($this->widgetRules($field) as $key => $widgetRules) {
            $rules[$key] = $widgetRules;
        }

        return $rules;
    }

    /**
     * Rules implied by the field's type, so the schema author states the enum
     * once in `options` instead of duplicating it in `validation_rules`.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    private function derivedTypeRules(array $field): array
    {
        $type = $field['type'] ?? null;

        if (in_array($type, ['select', 'radio'], true) && is_array($field['options'] ?? null)) {
            $values = $this->optionValues($field['options']);

            if ($values !== []) {
                return ['in:'.implode(',', $values)];
            }
        }

        return $type === 'checkbox' ? ['array'] : [];
    }

    /**
     * Widgets (GCS, pain canvas, dosage calculator) store structured values.
     * Their bounds are declared in the schema next to the widget rather than
     * hard-coded here, so a ward can add a scoring instrument without a
     * deployment.
     *
     * - `sub_fields`  -> `{parent}.{sub}`      e.g. gcs.eye
     * - `item_rules`  -> `{parent}.*.{key}`   e.g. pain_markers.*.x
     *
     * @param  array<string, mixed>  $field
     * @return array<string, array<int, mixed>>
     */
    private function widgetRules(array $field): array
    {
        $name = (string) ($field['name'] ?? '');
        $rules = [];

        $subFields = $field['sub_fields'] ?? null;
        if (is_array($subFields)) {
            foreach ($subFields as $sub) {
                if (! is_array($sub)) {
                    continue;
                }

                $subName = $sub['name'] ?? null;
                if (! is_string($subName)) {
                    continue;
                }

                /** @var array<int, mixed> $subRules */
                $subRules = is_array($sub['validation_rules'] ?? null) ? $sub['validation_rules'] : [];

                if ($subRules === []) {
                    continue;
                }

                $rules["{$name}.{$subName}"] = $subRules;
            }
        }

        $itemRules = $field['item_rules'] ?? null;
        if (is_array($itemRules)) {
            foreach ($itemRules as $key => $itemRule) {
                if (! is_array($itemRule)) {
                    continue;
                }

                $rules["{$name}.*.{$key}"] = $itemRule;
            }
        }

        return $rules;
    }

    /**
     * @param  array<int, mixed>  $options
     * @return list<string>
     */
    private function optionValues(array $options): array
    {
        $values = [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $values[] = (string) $option['value'];
            } elseif (is_scalar($option)) {
                $values[] = (string) $option;
            }
        }

        return $values;
    }

    /**
     * Strip values whose fields are currently hidden before the payload is
     * stored (FR-03), so a conditional answer never lingers invisibly.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pruneHidden(FormTemplate $template, array $payload, array $context = []): array
    {
        return $this->conditional->prune(array_merge($context, $payload), $template->fields());
    }

    /**
     * Convenience for the submission controller: prune, validate, and return
     * the validated payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateAndReturn(FormTemplate $template, array $payload, array $context = []): array
    {
        $pruned = $this->pruneHidden($template, $payload, $context);

        // Validate the caller's original payload (context included for the
        // conditions) but return only the form fields, so patient context is
        // never persisted into payload_data.
        $this->validate($template, $payload, $context)->validate();

        return $pruned;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function forSubmission(FormSubmission $submission, array $payload, array $context = []): \Illuminate\Validation\Validator
    {
        return $this->validate($submission->template()->firstOrFail(), $payload, $context);
    }
}

@props(['field', 'errors' => [], 'readonly' => false])

@php
    /**
     * One schema field rendered as a control.
     *
     * Every input carries the schema's own min/max so the browser and Alpine
     * enforce the same bounds the server will (AC-01.2), and every error is
     * wired through aria-describedby (ui-spec.md §Accessibility).
     */
    $name = $field['name'];
    $id = 'field-'.$name;
    $errorKey = $errors[$name] ?? null;
    $describedBy = $errorKey ? $id.'-error' : null;
    // Blade's bare @attributes only reads a variable named $attributes, and
    // @attributes($var) is not compiled at all, so the attribute string is
    // assembled here. Every value is schema-derived (a validated snake_case
    // field name, a generated id, a fixed class list) — never user input.
    $pairs = [
        'id' => $id,
        'name' => 'payload['.$name.']',
    ];

    if ($describedBy !== null) {
        $pairs['aria-describedby'] = $describedBy;
    }

    if ($readonly) {
        $pairs['readonly'] = 'readonly';
    }

    $pairs['class'] = 'field '.($errorKey ? 'field-error' : '').($readonly ? ' field-readonly' : '');

    $attributes = collect($pairs)
        ->map(static fn (string $value, string $key): string => e($key).'="'.e($value).'"')
        ->implode(' ');
@endphp

<div class="mb-4">
    <label for="{{ $id }}" class="mb-1 block text-body-sm font-medium text-text-secondary">
        {{ $field['label'] ?? $name }}
        @if (in_array('required', $field['validation_rules'] ?? [], true))
            <span class="text-danger-600" aria-hidden="true">*</span>
            <span class="sr-only">(wajib diisi)</span>
        @endif
        @if ($field['unit'] ?? null)
            <span class="font-normal text-text-muted">({{ $field['unit'] }})</span>
        @endif
    </label>

    @switch($field['type'])
        @case('number')
            <input type="number" {!! $attributes !!}
                   @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                   @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
                   step="{{ $field['step'] ?? 'any' }}"
                   @if ($readonly) readonly @endif
                   x-model.number="payload.{{ $name }}">
            @break

        @case('textarea')
            <textarea rows="4" {!! $attributes !!}
                      @if ($readonly) readonly @endif
                         x-model="payload.{{ $name }}"></textarea>
            @break

        @case('date')
            <input type="date" {!! $attributes !!} x-model="payload.{{ $name }}">
            @break

        @case('datetime')
            <input type="datetime-local" {!! $attributes !!} x-model="payload.{{ $name }}">
            @break

        @case('select')
            <select {!! $attributes !!} x-model="payload.{{ $name }}">
                <option value="">— Pilih —</option>
                @foreach ($field['options'] ?? [] as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            @break

        @case('radio')
            {{-- Grouped radio: fieldset/legend keeps the group label announced
                 once and gives arrow-key navigation for free. --}}
            <fieldset @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
                <legend class="sr-only">{{ $field['label'] ?? $name }}</legend>
                <div class="flex flex-wrap gap-3">
                    @foreach ($field['options'] ?? [] as $option)
                        <label class="flex items-center gap-2 rounded-md border border-border-subtle px-3 py-2 text-body-sm has-checked:border-brand-600 has-checked:bg-brand-50">
                            <input type="radio" name="payload[{{ $name }}]" value="{{ $option['value'] }}"
                                   x-model="payload.{{ $name }}"
                                   class="h-4 w-4 accent-brand-600"
                                   @disabled($readonly)>
                            {{ $option['label'] }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @break

        @default
            <input type="text" {!! $attributes !!} x-model="payload.{{ $name }}"
                   @if ($readonly) readonly @endif
                   @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    @endswitch

    @if ($field['help'] ?? null)
        <p class="mt-1 text-caption text-text-muted">{{ $field['help'] }}</p>
    @endif

    @if ($errorKey)
        {{-- role="alert" so the boundary violation is announced, not just drawn. --}}
        <p id="{{ $id }}-error" role="alert" class="mt-1 text-body-sm text-danger-600">{{ $errorKey[0] }}</p>
    @endif
</div>
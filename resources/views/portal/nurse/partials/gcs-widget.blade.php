{{--
    c-widget-gcs — ui-spec.md component inventory.

    Eye (1-4), Verbal (1-5) and Motor (1-6) as segmented pills. The total and
    the consciousness band are declared aria-live="polite" so a screen reader
    hears the result as soon as the third component is chosen.

    Keyboard: each group is a radio group, so arrow keys move between values
    without leaving the tab stop — ui-spec.md §Accessibility, keyboard order.
--}}
@props(['name' => 'gcs', 'readonly' => false])

@php
    $value = old('payload.gcs', []);
    $components = [
        'eye' => ['label' => 'Eye (E)', 'range' => [1, 4]],
        'verbal' => ['label' => 'Verbal (V)', 'range' => [1, 5]],
        'motor' => ['label' => 'Motor (M)', 'range' => [1, 6]],
    ];
@endphp

<fieldset class="card p-4"
          x-data="gcsWidget(@js($value), @js($readonly))">
    <legend class="text-heading-sm">Skala Koma Glasgow (GCS)</legend>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($components as $key => $meta)
            <div>
                <span class="mb-2 block text-caption text-text-secondary">{{ $meta['label'] }}</span>
                <div class="flex flex-wrap gap-1" role="radiogroup"
                     aria-label="{{ $meta['label'] }}">
                    @for ($i = $meta['range'][0]; $i <= $meta['range'][1]; $i++)
                        <label class="cursor-pointer">
                            <input type="radio" class="peer sr-only"
                                   name="payload[{{ $name }}][{{ $key }}]"
                                   value="{{ $i }}"
                                   x-model="parts.{{ $key }}"
                                   @disabled($readonly)>
                            <span class="grid h-9 w-9 place-items-center rounded-md border border-border-subtle
                                         font-mono-md text-text-secondary transition-colors duration-150
                                         peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white
                                         peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">
                                {{ $i }}
                            </span>
                        </label>
                    @endfor
                </div>
            </div>
        @endforeach
    </div>

    {{-- aria-live: the computed total is the point of the widget. --}}
    <div class="mt-4 flex flex-wrap items-center gap-3" aria-live="polite">
        <template x-if="!complete">
            <p class="text-body-sm text-warning-600">
                {{ 'Pilih ketiga parameter (E, V, M) untuk menghitung GCS.' }}
            </p>
        </template>

        <template x-if="complete">
            <div class="flex items-center gap-3 rounded-md bg-success-50 px-4 py-2">
                <span class="text-heading-sm text-success-700">
                    Total GCS: <span class="font-mono-md" x-text="total"></span>
                </span>
                <span class="pill bg-success-50 text-success-700" x-text="label"></span>
            </div>
        </template>
    </div>

    {{-- Kept in the DOM so the payload round-trips; the widget owns the math. --}}
    <input type="hidden" name="payload[{{ $name }}][total]" :value="complete ? total : ''">
    <input type="hidden" name="payload[{{ $name }}][category]" :value="complete ? category : ''">
</fieldset>
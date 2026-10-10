{{--
    c-dosage_calculator — FR-07.

    Dose is computed from body weight against the rule set declared in the form
    schema. AC-02.2 requires the DPJP to be able to override the computed value
    and record why, which is what the justification field is for.
--}}
@props(['name' => 'pediatric_dosage', 'rules' => [], 'weightField' => 'weight_kg'])

<fieldset class="card p-4" x-data="dosageWidget(@js($rules), @js($weightField), payload)">
    <legend class="text-heading-sm">Perhitungan Dosis Obat Pediatrik</legend>

    @if ($rules === [])
        <p class="text-body-sm text-text-muted">Formulir ini tidak memuat aturan dosis.</p>
    @else
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="drug" class="mb-1 block text-caption text-text-secondary">Obat</label>
                <select id="drug" x-model="drug" class="field">
                    <option value="">— Pilih obat —</option>
                    @foreach ($rules as $rule)
                        <option value="{{ $rule['drug'] }}">{{ $rule['drug'] }} ({{ $rule['generic'] }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="{{ $weightField }}" class="mb-1 block text-caption text-text-secondary">Berat ({{ $weightField }})</label>
                <input id="{{ $weightField }}" type="number" step="0.1" min="0.5" max="300"
                       x-model.number="payload.{{ $weightField }}" class="field font-mono-md">
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3" aria-live="polite">
            @foreach ([
                ['Dosis per pemberian', 'dose_mg', 'mg'],
                ['Dosis per hari', 'dose_per_day_mg', 'mg/hari'],
                ['Maksimum harian', 'max_daily_mg', 'mg/hari'],
            ] as [$label, $key, $unit])
                <div class="rounded-md bg-surface-subtle p-3">
                    <p class="text-caption text-text-secondary">{{ $label }}</p>
                    <p class="font-mono-md text-text-primary">
                        <span x-text="result?.{{ $key }} ?? '—'"></span>
                        <span class="text-body-sm text-text-muted">{{ $unit }}</span>
                    </p>
                </div>
            @endforeach
        </div>

        {{-- FR-07: warn when the computed day total breaches the ceiling. --}}
        <p x-show="result?.exceeds_max" x-cloak role="alert"
           class="mt-3 rounded-md border border-danger-600 bg-danger-50 px-3 py-2 text-body-sm text-danger-600">
            Dosis harian melebihi batas maksimum untuk obat ini. DPJP wajib mengoreksi atau
            mendokumentasikan alasannya.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="override" class="mb-1 block text-caption text-text-secondary">Koreksi DPJP (mg)</label>
                <input id="override" type="number" step="0.01" min="0" x-model.number="override"
                       class="field font-mono-md" placeholder="Kosongkan bila tidak dikoreksi">
            </div>
            <div>
                <label for="reason" class="mb-1 block text-caption text-text-secondary">Alasan koreksi</label>
                <input id="reason" type="text" x-model="reason" maxlength="500" class="field"
                       placeholder="Wajib diisi bila dosis dikoreksi">
            </div>
        </div>

        <template x-if="drug">
            <input type="hidden" name="payload[{{ $name }}][drug]" :value="result?.drug">
            <input type="hidden" name="payload[{{ $name }}][dose_mg]" :value="displayedDose">
            <input type="hidden" name="payload[{{ $name }}][dose_per_day_mg]" :value="result?.dose_per_day_mg">
            <input type="hidden" name="payload[{{ $name }}][max_daily_mg]" :value="result?.max_daily_mg">
            <input type="hidden" name="payload[{{ $name }}][exceeds_max]" :value="result?.exceeds_max">
            <input type="hidden" name="payload[{{ $name }}][override_reason]" :value="override === null ? '' : reason">
        </template>
    @endif
</fieldset>

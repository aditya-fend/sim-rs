@extends('layouts.app')

@section('title', 'Asesmen Medis')

@section('patient-strip')
    @include('portal.nurse.partials.patient-strip', ['patient' => $patient, 'encounter' => $encounter])
@endsection

@section('content')
<div class="mx-auto max-w-[1600px]"
     x-data="assessmentForm(@js($unlocked?->id), @js($signEndpoint))"
     x-on:icd10-selected="selectedCode = $event.detail.code"
     x-cloak>

    <header class="mb-6">
        <h1 class="text-display-lg">Asesmen Medis & Penandatanganan</h1>
        <p class="text-body-sm text-text-muted">
            {{ $encounter->encounter_number }} &middot; {{ $encounter->service_unit }}
        </p>
    </header>

    <div class="grid gap-6 xl:grid-cols-12">
        {{-- Left: everything the nurse recorded, decrypted for clinical review. --}}
        <div class="space-y-6 xl:col-span-7">
            <h2 class="text-heading-sm">Temuan Perawat</h2>

            @forelse ($submissions as $submission)
                <article class="card p-4">
                    <header class="mb-3 flex flex-wrap items-center gap-2 border-b border-border-subtle pb-2">
                        <span class="text-heading-sm">{{ $submission->template?->name ?? 'Formulir' }}</span>
                        <span class="pill bg-surface-subtle text-text-secondary font-mono-sm">
                            v{{ $submission->schema_version }}
                        </span>
                        <span class="pill {{ $submission->isLocked() ? 'bg-success-50 text-success-700' : 'bg-brand-50 text-brand-700' }}">
                            {{ $submission->status->value }}
                        </span>
                        <span class="ml-auto text-caption text-text-muted">
                            oleh {{ $submission->author?->name }}
                            {{ $submission->created_at?->format('d/m/Y H:i') }}
                        </span>
                    </header>

                    @if ($submission->gcs_total !== null)
                        <p class="mb-2 text-body-sm">
                            GCS
                            <strong class="font-mono-md">{{ $submission->gcs_total }}</strong>
                            @if ($submission->triage_category !== null)
                                &middot; {{ $submission->triage_category->presentation()['label'] }}
                            @endif
                        </p>
                    @endif

                    @if ($submission->anamnesis() !== null)
                        <div class="rounded-md border border-brand-600 bg-brand-50 p-3">
                            <p class="text-caption text-brand-700">
                                Anamnesis (terenkripsi AES-256-GCM, telah didekripsi untuk Ditampilkan)
                            </p>
                            <p class="mt-1 text-body-sm text-text-primary">{{ $submission->anamnesis() }}</p>
                        </div>
                    @endif

                    <dl class="mt-3 grid gap-x-6 gap-y-1 text-body-sm sm:grid-cols-2">
                        @foreach ($submission->payload_data as $key => $value)
                            @continue(is_array($value))
                            <div class="flex justify-between gap-2 border-b border-border-subtle py-1">
                                <dt class="truncate text-text-muted">{{ str_replace('_', ' ', $key) }}</dt>
                                <dd class="text-right font-mono-sm">{{ is_bool($value) ? ($value ? 'ya' : 'tidak') : $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if (is_array($submission->payload_data['pain_markers'] ?? null))
                        <p class="mt-2 text-body-sm text-text-secondary">
                            Penanda nyeri:
                            <span class="font-mono-sm">{{ count($submission->payload_data['pain_markers']) }}</span> lokasi
                            (puncak
                            <span class="font-mono-sm">
                                {{ app(\App\Services\Clinical\PainMarkerNormalizer::class)->peakSeverity($submission->payload_data['pain_markers']) }}/10
                            </span>)
                        </p>
                    @endif

                    @if ($submission->isLocked())
                        <a href="{{ route('portal.doctor.record', $submission) }}"
                           class="btn-secondary mt-3 px-3 py-1 text-body-sm">Lihat rekam medis terkunci</a>
                    @endif
                </article>
            @empty
                <div class="border-2 border-dashed border-border-strong p-8 text-center text-body-sm text-text-muted">
                    Belum ada formulir yang diisi untuk kunjungan ini.
                </div>
            @endforelse
        </div>

        {{-- Right: the physician's own section. --}}
        <aside class="xl:col-span-5">
            @if ($unlocked === null)
                <div class="card p-6">
                    <p class="text-heading-sm">Semua formulir sudah ditandatangani</p>
                    <p class="mt-2 text-body-sm text-text-muted">
                        Rekam medis pada kunjungan ini telah dikunci permanen sesuai Permenkes No. 24/2022.
                    </p>
                </div>
            @else
                <form class="card space-y-4 p-5" @submit.prevent="submit()">
                    <h2 class="text-heading-sm">Temuan &amp; Diagnosis Dokter</h2>

                    <div>
                        <label for="primary_icd10_code" class="mb-1 block text-body-sm font-medium text-text-secondary">
                            Diagnosis Utama (ICD-10)
                        </label>
                        {{-- c-icd10-picker: searchable select with all five states. --}}
                        <div x-data="{ open: false, q: '', search() {
                                const q = this.q.toLowerCase();
                                return Object.entries(@js($icd10)).filter(([c, d]) =>
                                    c.toLowerCase().includes(q) || d.toLowerCase().includes(q)).slice(0, 8);
                            } }">
                            <input type="text" x-model="q" @focus="open = true" @blur="setTimeout(() => open = false, 150)"
                                   placeholder="Cari kode atau diagnosis…"
                                   class="field" role="combobox" aria-expanded="false"
                                   aria-controls="icd10-listbox" aria-autocomplete="list">
                            <ul x-show="open" x-cloak id="icd10-listbox" role="listbox"
                                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border-subtle bg-surface-base shadow-high">
                                <template x-for="[code, description] in search()" :key="code">
                                    <li role="option"
                                        {{-- $parent would write onto the inner
                                             picker scope, which does not own
                                             selectedCode; the event bubbles to
                                             the assessmentForm component. --}}
                                        @mousedown.prevent="q = code; open = false; $dispatch('icd10-selected', { code })"
                                        class="cursor-pointer px-3 py-2 text-body-sm hover:bg-surface-subtle">
                                        <span class="font-mono-sm" x-text="code"></span>
                                        <span class="text-text-muted" x-text="description"></span>
                                    </li>
                                </template>
                            </ul>
                            <input type="hidden" name="primary_icd10_code" x-model="selectedCode">
                            <p class="mt-1 text-caption text-text-muted" x-show="selectedCode">Dipilih: <span class="font-mono-sm" x-text="selectedCode"></span></p>
                        </div>
                        <p x-show="errors.primary_icd10_code" x-cloak role="alert"
                           class="mt-1 text-body-sm text-danger-600" x-text="errors.primary_icd10_code?.[0]"></p>
                    </div>

                    <div>
                        <label for="objective_findings" class="mb-1 block text-body-sm font-medium text-text-secondary">
                            Temuan Objektif
                        </label>
                        <textarea id="objective_findings" name="objective_findings" rows="4"
                                  x-model="objective_findings" class="field"></textarea>
                    </div>

                    <div class="rounded-md border border-warning-600 bg-warning-50 p-3">
                        <p class="text-body-sm font-medium text-warning-600">
                            {{ 'Konfirmasi Penguncian Rekam Medis' }}
                        </p>
                        <p class="mt-1 text-body-sm text-text-secondary">
                            {{ 'Sesuai Permenkes No. 24/2022, rekam medis yang telah ditandatangani tidak dapat diubah kembali tanpa prosedur addendum resmi. Lanjutkan?' }}
                        </p>
                    </div>

                    <button type="submit" id="assessment-sign" class="btn-danger w-full" x-bind:disabled="signing || !selectedCode">
                        <span x-show="!signing">{{ 'Tandatangani & Kunci Permanen' }}</span>
                        <span x-show="signing">Menandatangani…</span>
                    </button>
                </form>
            @endif
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Submission outcomes are announced through the shared status region.
window.addEventListener('klinikform:toast', (event) => {
    const { tone, message } = event.detail;
    const region = document.getElementById('toast-region');
    if (!region) return;

    const el = document.createElement('div');
    el.className = `pointer-events-auto rounded-md px-4 py-3 text-body-sm shadow-high ${
        {
            success: 'bg-success-50 text-success-700',
            warning: 'bg-warning-50 text-warning-600',
            error: 'bg-danger-50 text-danger-600',
        }[tone] ?? ''
    }`;
    el.textContent = message;
    region.appendChild(el);

    setTimeout(() => el.remove(), 6000);
});
</script>
@endpush

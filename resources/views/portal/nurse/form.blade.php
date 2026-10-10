@extends('layouts.app')

@section('title', 'Formulir '.$template->code)

@section('patient-strip')
    @include('portal.nurse.partials.patient-strip', ['patient' => $patient, 'encounter' => $encounter])
@endsection

@section('content')

<div class="mx-auto max-w-[1600px]"
     x-data="clinicalForm({
        templateId: @js($template->id),
        encounterId: @js($encounter->id),
        endpoint: @js($submissionEndpoint),
        draftEndpoint: @js($draftEndpoint),
        draftKey: @js('draft_'.$encounter->id.'_'.$template->id),
        initial: @js(old('payload_data', $draft?->draft_payload ?? [])),
        localIntervalSeconds: @js(config('klinikform.drafts.local_storage_interval_seconds')),
        serverIntervalSeconds: @js(config('klinikform.drafts.server_sync_seconds')),
     })"
     x-cloak>

    {{-- AC-04.2 recovery banner: slide-down, actionable, dismissible. --}}
    <template x-if="recoveredAt">
        <div class="draft-banner-enter mb-6 rounded-md border border-warning-600 bg-warning-50 p-4"
             role="alert">
            <p class="text-heading-sm text-warning-600">
                {{ 'Draf Belum Tersimpan Ditemukan' }}
            </p>
            <p class="mt-1 text-body-sm text-text-secondary">
                {{ 'Tersedia draf pemeriksaan lokal dari' }}
                <span class="font-mono-sm" x-text="new Date(recoveredAt).toLocaleString('id-ID')"></span>.
                {{ 'Pulihkan data?' }}
            </p>
            <div class="mt-3 flex gap-2">
                <button type="button" class="btn-primary" x-on:click="restore()">
                    {{ 'Pulihkan Draf' }}
                </button>
                <button type="button" class="btn-secondary" x-on:click="discard()">
                    {{ 'Buang & Buat Baru' }}
                </button>
            </div>
        </div>
    </template>

    <div class="grid gap-6 xl:grid-cols-12">
        {{-- Clinical entry column: 8 of 12 columns at desktop-standard. --}}
        <div class="xl:col-span-8">
            <header class="mb-4 flex flex-wrap items-center gap-3">
                <div>
                    <h1 class="text-display-lg">{{ $template->name }}</h1>
                    <p class="text-body-sm text-text-muted">
                        <span class="font-mono-sm">{{ $template->code }}</span>
                        &middot; Skema v{{ $template->version }}
                        @if (! $template->is_active)
                            <span class="pill ml-1 bg-warning-50 text-warning-600">versi historis</span>
                        @endif
                    </p>
                </div>

                {{-- c-draft-banner, inline pill variant. --}}
                <div class="pill ml-auto" :class="{
                        'bg-success-50 text-success-700': draftStatus === 'saved',
                        'bg-brand-50 text-brand-700': draftStatus === 'saving',
                        'bg-warning-50 text-warning-600': draftStatus === 'offline_cached',
                     }" role="status" aria-live="polite">
                    <span class="h-2 w-2 rounded-full bg-current"
                          :class="draftStatus === 'saving' ? 'animate-pulse' : ''"></span>
                    <span x-text="{
                        saved: 'Draf tersimpan lokal',
                        saving: 'Menyinkronkan draf…',
                        offline_cached: 'Draf tersimpan lokal (jaringan terputus)',
                    }[draftStatus]"></span>
                    <span x-show="lastSavedAt" class="font-mono-sm"
                          x-text="lastSavedAt ? lastSavedAt.toLocaleTimeString('id-ID') : ''"></span>
                </div>
            </header>

            <form @submit.prevent="submit()" novalidate>
                @foreach ($template->schema_definition['sections'] ?? [] as $section)
                    <section class="card mb-6 p-5">
                        <h2 class="text-heading-md">{{ $section['label'] ?? $section['key'] }}</h2>
                        @if ($section['description'] ?? null)
                            <p class="mb-3 text-body-sm text-text-muted">{{ $section['description'] }}</p>
                        @endif

                        @foreach ($section['fields'] ?? [] as $field)
                            @if ($field['type'] === 'gcs_calculator')
                                @include('portal.nurse.partials.gcs-widget', [
                                    'name' => $field['name'],
                                    'errors' => $errors,
                                ])
                            @elseif ($field['type'] === 'dosage_calculator')
                                @include('portal.nurse.partials.dosage-widget', [
                                    'name' => $field['name'],
                                    'rules' => $field['rules'] ?? [],
                                    'weightField' => $field['weight_field'] ?? 'weight_kg',
                                ])
                            @elseif ($field['type'] === 'pain_canvas')
                                @include('portal.nurse.partials.pain-canvas', [
                                    'name' => $field['name'],
                                    'regions' => $field['regions'] ?? [],
                                    'errors' => $errors,
                                ])
                            @else
                                @include('portal.nurse.partials.field', [
                                    'field' => $field,
                                    'errors' => $errors->get($field['name']),
                                ])
                            @endif
                        @endforeach
                    </section>
                @endforeach

                <div class="card sticky bottom-4 flex flex-wrap items-center gap-3 p-4 shadow-high">
                    <p class="text-body-sm text-text-muted">
                        Tekan <kbd class="rounded border border-border-subtle px-1">Ctrl</kbd>
                        + <kbd class="rounded border border-border-subtle px-1">Enter</kbd> untuk menyimpan.
                    </p>

                    <button type="submit" class="btn-primary ml-auto" x-bind:disabled="submitting">
                        <span x-show="!submitting">Simpan Triase</span>
                        <span x-show="submitting">Menyimpan…</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- Context rail: 4 of 12 columns, stacks beneath on tablet. --}}
        <aside class="xl:col-span-4">
            <div class="card p-4">
                <p class="text-heading-sm">Ringkasan Klinis</p>
                <dl class="mt-3 space-y-2 text-body-sm" x-data="formSummary()">
                    <div class="flex justify-between">
                        <dt class="text-text-muted">Kategori triase</dt>
                        <dd class="font-semibold" x-text="triageLabel">—</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-muted">Tekanan darah</dt>
                        <dd class="font-mono-md" x-text="bloodPressure">—</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-muted">Nadi</dt>
                        <dd class="font-mono-md"><span x-text="payload.heart_rate">—</span> x/menit</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-muted">Suhu</dt>
                        <dd class="font-mono-md"><span x-text="payload.body_temperature">—</span> °C</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-muted">Nyeri tertinggi</dt>
                        <dd class="font-mono-md" x-text="peakPain">—</dd>
                    </div>
                </dl>
            </div>
        </aside>
    </div>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('formSummary', () => ({
            get triageLabel() {
                return window.KlinikForm.triage[this.payload.triage_category]?.label ?? '—';
            },
            get bloodPressure() {
                if (!this.payload.systolic || !this.payload.diastolic) return '—';
                return `${this.payload.systolic}/${this.payload.diastolic} mmHg`;
            },
            get peakPain() {
                const markers = this.payload.pain_markers ?? [];
                if (!Array.isArray(markers) || markers.length === 0) return '—';
                return `${Math.max(...markers.map((m) => Number(m.severity) || 0))}/10`;
            },
        }));
    });

    // Toast host for submission outcomes.
    document.addEventListener('klinikform:toast', (event) => {
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
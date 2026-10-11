@extends('layouts.app')

@section('title', 'Intake Triase')

@section('content')
{{-- The Alpine scope wraps the whole page: the lookup result panel and the
     encounter table read `result`, so it cannot live on the form alone. --}}
<div class="mx-auto max-w-[1600px]"
     x-data="patientLookup({ initial: @js($initialQuery) })"
     x-cloak>

    <header class="mb-6">
        <h1 class="text-display-lg">Intake Triase Gawat Darurat</h1>
        <p class="text-body-sm text-text-muted">
            Cari pasien dengan NIK 16 digit atau Nomor Rekam Medis, lalu buka formulir triase.
        </p>
    </header>

    {{-- Patient lookup: the keyboard entry point for the whole portal
         (ui-spec.md §Accessibility, keyboard order). --}}
    <form class="card mb-6 flex flex-wrap items-end gap-3 p-4" role="search"
          @submit.prevent="search()">
        <div class="min-w-56 flex-1">
            <label for="nik" class="mb-1 block text-caption text-text-secondary">Nomor Induk Kependudukan (NIK)</label>
            <input id="nik" type="text" inputmode="numeric" maxlength="19" autocomplete="off"
                   x-model="nik" x-on:keydown.enter="search()"
                   :aria-invalid="error ? 'true' : 'false'"
                   aria-describedby="nik-error"
                   placeholder="3315012345670001"
                   class="field font-mono-md focus:border-brand-600 focus:ring-2 focus:ring-brand-600/20"
                   @error('nik') field-error @enderror>
            <p id="nik-error" x-show="error" x-text="error"
               class="mt-1 text-body-sm text-danger-600" x-cloak></p>
        </div>

        <button type="button" class="btn-primary" x-on:click="search()" x-bind:disabled="loading">
            <span x-show="!loading">Cari Pasien</span>
            <span x-show="loading">Mencari…</span>
        </button>

        <div class="w-full border-t border-border-subtle pt-3">
            <label for="mrn" class="mb-1 block text-caption text-text-secondary">atau Nomor Rekam Medis</label>
            <input id="mrn" type="text" placeholder="00-48-21" class="field w-40 font-mono-sm">
        </div>
    </form>

    {{-- Live search result --}}
    <template x-if="result">
        <div class="card mb-6 flex flex-wrap items-center gap-4 border-brand-600 p-4">
            <div>
                <p class="text-heading-sm" x-text="result.full_name"></p>
                <p class="text-body-sm text-text-muted">
                    RM <span class="font-mono-sm" x-text="result.medical_record_number"></span>
                    &middot; NIK <span class="font-mono-sm" x-text="result.nik_masked"></span>
                    &middot; <span x-text="result.age_years"></span> tahun
                    &middot; <span x-text="result.gender === \'male\' ? \'L\' : \'P\'"></span>
                </p>
            </div>
            <a class="btn-primary ml-auto" x-show="result.open_encounter_id" :href="formUrl">
                Buka Formulir Triase
            </a>
            <button class="btn-primary ml-auto" x-show="!result.open_encounter_id" x-on:click="createEncounter()">
                Buat Kunjungan IGD
            </button>
        </div>
    </template>

    <h2 class="mb-3 text-heading-sm">Kunjungan menunggu triase</h2>

    <div class="card overflow-x-auto" tabindex="0">
        <table class="w-full border-collapse text-body-sm">
            <caption class="sr-only">Daftar kunjungan yang menunggu formulir triase</caption>
            <thead>
                <tr class="border-b border-border-subtle bg-surface-subtle text-left text-caption text-text-secondary">
                    <th scope="col" class="px-4 py-2">No. Kunjungan</th>
                    <th scope="col" class="px-4 py-2">Pasien</th>
                    <th scope="col" class="px-4 py-2">Unit</th>
                    <th scope="col" class="px-4 py-2">Masuk</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($encounters as $encounter)
                    <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-subtle">
                        <td class="px-4 py-2 font-mono-sm">{{ $encounter->encounter_number }}</td>
                        <td class="px-4 py-2">{{ $encounter->patient?->full_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $encounter->service_unit }}</td>
                        <td class="px-4 py-2 font-mono-sm">{{ $encounter->admission_time->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            <span class="pill bg-brand-50 text-brand-700">{{ $encounter->status->value }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('portal.nurse.intake.form', $encounter) }}" class="btn-secondary px-3 py-1 text-body-sm">
                                Isi Triase
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="border-2 border-dashed border-border-strong p-8 text-center text-body-sm text-text-muted">
                            Tidak ada kunjungan yang menunggu triase.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

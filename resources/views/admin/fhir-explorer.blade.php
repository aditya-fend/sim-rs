@extends('layouts.app')

@section('title', 'Explorer SatuSehat FHIR')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6">
        <h1 class="text-display-lg">{{ 'Explorer SatuSehat' }}</h1>
        <p class="text-body-sm text-text-muted">
            FR-16 s.d. FR-18 &mdash; dibaca melalui gateway sungguhan, jadi yang tampil persis sama dengan yang diterima integrator.
        </p>
    </header>

    <div class="grid gap-6 xl:grid-cols-12">
        <div class="xl:col-span-4">
            <div class="card p-5">
                <h2 class="text-heading-sm">{{ 'Pilih Encounter' }}</h2>

                <form method="GET" action="{{ route('admin.fhir.explorer') }}" class="mt-3">
                    <label for="encounter" class="mb-1 block text-caption text-text-secondary">{{ 'Encounter' }}</label>
                    <select id="encounter" name="encounter" class="field">
                        <option value="">{{ '— pilih —' }}</option>
                        @foreach ($encounters as $encounter)
                            <option value="{{ $encounter->id }}"
                                @selected(request()->query('encounter') === $encounter->id)>
                                {{ $encounter->id }} &mdash; {{ $encounter->patient?->full_name }}
                            </option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn-primary mt-3 w-full">Tampilkan Bundle</button>
                </form>

                <p class="mt-4 border-t border-border-subtle pt-3 text-caption text-text-muted">
                    {{ 'Gateway memakai token:' }}
                    <span class="font-mono-sm">{{ $fhirToken === '' ? '(FAPI_TOKEN belum diisi)' : 'di-set' }}</span>
                </p>
            </div>
        </div>

        <div class="xl:col-span-8">
            @if ($selected === null)
                <div class="card p-5">
                    <p class="text-body-sm text-text-muted">
                        {{ 'Pilih encounter untuk melihat resource Encounter dan bundle Condition yang dikembalikan gateway.' }}
                    </p>
                </div>
            @else
                <div class="card p-5">
                    <div class="flex items-center gap-3">
                        <h2 class="text-heading-sm">{{ $selected['label'] }}</h2>
                        <span class="pill bg-success-50 text-success-700">HTTP {{ $selected['json']['resourceType'] ?? '—' }}</span>
                    </div>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-surface-subtle p-3 font-mono-sm">{{ json_encode($selected['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>

                @if (isset($selected['conditions']))
                    <div class="card mt-4 p-5">
                        <h2 class="text-heading-sm">{{ 'Condition Bundle' }}</h2>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-surface-subtle p-3 font-mono-sm">{{ json_encode($selected['conditions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
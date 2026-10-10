@extends('layouts.app')

@section('title', 'Simulator BPJS VClaim')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6">
        <h1 class="text-display-lg">{{ 'Simulator BPJS VClaim' }}</h1>
        <p class="text-body-sm text-text-muted">
            user-flow.md Flow 4 &middot; FR-19 s.d. FR-21 &mdash; handshake HMAC dihitung di sisi server,
            secret tidak pernah dikirim ke browser.
        </p>
    </header>

    @if ($errors->any())
        <ul role="alert" class="mb-4 rounded-md border border-danger-600 bg-danger-50 px-4 py-2 text-body-sm text-danger-600">
            @foreach ($errors->all() as $error)
                <li>{{ is_array($error) ? implode(' ', $error) : $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="grid gap-6 xl:grid-cols-12">
        <div class="xl:col-span-5">
            <div class="card p-5">
                <h2 class="text-heading-sm">{{ 'Permintaan SEP' }}</h2>

                <form method="POST" action="{{ route('admin.bpjs.simulator') }}" class="mt-4 space-y-3">
                    @csrf

                    <div>
                        <label for="noKartu" class="mb-1 block text-caption text-text-secondary">{{ 'Nomor Kartu BPJS' }}</label>
                        <input id="noKartu" name="noKartu" type="text" required inputmode="numeric" maxlength="13"
                               pattern="\d{13}" value="{{ old('noKartu', '0001234567890') }}" class="field font-mono-md">
                    </div>

                    <div>
                        <label for="tglSep" class="mb-1 block text-caption text-text-secondary">{{ 'Tanggal SEP' }}</label>
                        <input id="tglSep" name="tglSep" type="date" required
                               value="{{ old('tglSep', now()->toDateString()) }}" class="field">
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="ppkPelayanan" class="mb-1 block text-caption text-text-secondary">{{ 'PPK Pelayanan' }}</label>
                            <input id="ppkPelayanan" name="ppkPelayanan" type="text" required maxlength="16"
                                   value="{{ old('ppkPelayanan', '0301R001') }}" class="field font-mono-md">
                        </div>
                        <div>
                            <label for="jnsPelayanan" class="mb-1 block text-caption text-text-secondary">{{ 'Jenis Pelayanan' }}</label>
                            <select id="jnsPelayanan" name="jnsPelayanan" required class="field">
                                <option value="1" @selected(old('jnsPelayanan', '1') === '1')>{{ 'Rawat Jalan' }}</option>
                                <option value="2" @selected(old('jnsPelayanan') === '2')>{{ 'Rawat Inap' }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="diagAwal" class="mb-1 block text-caption text-text-secondary">{{ 'Diagnosis Awal (ICD-10)' }}</label>
                            <input id="diagAwal" name="diagAwal" type="text" required maxlength="16"
                                   value="{{ old('diagAwal', 'K29.7') }}" class="field font-mono-md">
                        </div>
                        <div>
                            <label for="poli_tujuan" class="mb-1 block text-caption text-text-secondary">{{ 'Poli Tujuan' }}</label>
                            <input id="poli_tujuan" name="poli_tujuan" type="text" required maxlength="16"
                                   value="{{ old('poli_tujuan', '13') }}" class="field font-mono-md">
                        </div>
                    </div>

                    <div>
                        <label for="encounter_id" class="mb-1 block text-caption text-text-secondary">
                            {{ 'Encounter (opsional)' }}
                        </label>
                        <select id="encounter_id" name="encounter_id" class="field">
                            <option value="">{{ '— tanpa encounter —' }}</option>
                            @foreach ($encounters as $encounter)
                                <option value="{{ $encounter->id }}" @selected(old('encounter_id') === $encounter->id)>
                                    {{ $encounter->id }} &mdash; {{ $encounter->patient?->full_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="catatan" class="mb-1 block text-caption text-text-secondary">{{ 'Catatan' }}</label>
                        <input id="catatan" name="catatan" type="text" maxlength="255"
                               value="{{ old('catatan') }}" class="field">
                    </div>

                    <p class="text-caption text-text-muted">
                        {{ 'X-cons-id: ' }}<span class="font-mono-sm">{{ $consId }}</span>
                    </p>

                    <button type="submit" class="btn-primary w-full">Kirim Permintaan</button>
                </form>
            </div>
        </div>

        <div class="xl:col-span-7">
            @if ($result !== null)
                <div class="card p-5">
                    <div class="flex items-center gap-3">
                        <h2 class="text-heading-sm">{{ 'Respons' }}</h2>
                        <span class="pill {{ $result['status'] === 200 ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-600' }}">
                            HTTP {{ $result['status'] }}
                        </span>
                    </div>

                    @if ($payload !== null)
                        <p class="mt-3 text-caption text-text-secondary">{{ 'Request body' }}</p>
                        <pre class="mt-1 overflow-x-auto rounded-md bg-surface-subtle p-3 font-mono-sm">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif

                    <p class="mt-4 text-caption text-text-secondary">{{ 'Response body' }}</p>
                    <pre class="mt-1 overflow-x-auto rounded-md bg-surface-subtle p-3 font-mono-sm">{{ json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @else
                <div class="card p-5">
                    <p class="text-body-sm text-text-muted">
                        {{ 'Kirim formulir untuk melihat request body dan respons BPJS apa adanya, termasuk penolakan karena tanda tangan atau timestamp.' }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
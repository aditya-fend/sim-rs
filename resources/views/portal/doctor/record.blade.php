@extends('layouts.app')

@section('title', 'Rekam Medis Terkunci')

@section('patient-strip')
    @include('portal.nurse.partials.patient-strip', [
        'patient' => $submission->encounter?->patient,
        'encounter' => $submission->encounter,
    ])
@endsection

@section('content')
<div class="mx-auto max-w-4xl">

    {{-- c-signature-stamp: the Permenkes verification block. --}}
    <div class="lock-crossfade card mb-6 p-5 {{ $verified ? 'border-success-700 bg-success-50' : 'border-danger-600 bg-danger-50' }}">
        <div class="flex items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full {{ $verified ? 'bg-success-700' : 'bg-danger-600' }} text-white">
                {{ $verified ? '✓' : '!' }}
            </span>
            <div>
                <p class="text-heading-sm {{ $verified ? 'text-success-700' : 'text-danger-600' }}">
                    {{ $verified ? 'Dokumen Rekam Medis Terverifikasi Secara Elektronik' : 'Integritas Dokumen Bermasalah' }}
                </p>
                <p class="text-body-sm text-text-secondary">
                    @if ($verified)
                        SHA-256 atas isi dokumen cocok dengan tanda tangan yang tersimpan.
                    @else
                        Tanda tangan tidak cocok dengan isi dokumen saat ini. Hubungi Kepala Instalasi Rekam Medis.
                    @endif
                </p>
            </div>
        </div>
    </div>

    <div class="card mb-6 p-5">
        <h1 class="text-heading-md">{{ $submission->template?->name }}</h1>
        <p class="text-body-sm text-text-muted">
            {{ $submission->template?->code }} &middot; Skema v{{ $submission->schema_version }}
        </p>

        <dl class="mt-4 grid gap-x-6 gap-y-2 text-body-sm sm:grid-cols-2">
            @foreach ([
                'Status' => $submission->status->value,
                'Diagnosis (ICD-10)' => $submission->primary_icd10_code,
                'GCS' => $submission->gcs_total,
                'Kategori triase' => $submission->triage_category?->presentation()['label'],
                'Ditulis oleh' => $submission->author?->name,
                'Ditandatangani oleh' => $submission->signer?->name,
            ] as $label => $value)
                <div class="flex justify-between gap-2 border-b border-border-subtle py-1">
                    <dt class="text-text-muted">{{ $label }}</dt>
                    <dd class="text-right">{{ $value ?? '—' }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($submission->anamnesis() !== null)
            <div class="mt-4 rounded-md border border-border-subtle bg-surface-subtle p-3">
                <p class="text-caption text-text-secondary">Anamnesis</p>
                <p class="mt-1 text-body-sm">{{ $submission->anamnesis() }}</p>
            </div>
        @endif

        @if ($submission->objective_findings !== null)
            <div class="mt-3 rounded-md border border-border-subtle bg-surface-subtle p-3">
                <p class="text-caption text-text-secondary">Temuan Objektif Dokter</p>
                <p class="mt-1 text-body-sm">{{ $submission->objective_findings }}</p>
            </div>
        @endif
    </div>

    {{-- Official certificate block variant of the stamp, for print/export. --}}
    <div class="card p-5">
        <p class="text-heading-sm">Sertifikat Penandatanganan Elektronik</p>
        <dl class="mt-3 space-y-2 text-body-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-text-muted">Nama Praktisi</dt>
                <dd class="text-right font-medium">{{ $submission->signer?->name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-text-muted">SIP</dt>
                <dd class="text-right font-mono-sm">{{ $submission->signer?->sip_number ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-text-muted">Waktu penandatanganan</dt>
                <dd class="text-right font-mono-sm">{{ $submission->signed_at?->format('d/m/Y H:i:s T') }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-text-muted">Checksum SHA-256</dt>
                <dd class="text-right font-mono-sm">{{ $submission->signature_hash }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-6 flex gap-2">
        <a href="{{ route('portal.doctor.worklist') }}" class="btn-secondary">Kembali ke Worklist</a>
    </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Administrasi SIMRS')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6">
        <h1 class="text-display-lg">{{ 'Administrasi SIMRS' }}</h1>
        <p class="text-body-sm text-text-muted">
            {{ 'FR-15 — reconcile schema formulir, integrasi dan data klinis aktif.' }}
        </p>
    </header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('admin.schemas.index') }}" class="card p-5 transition-colors duration-150 hover:border-brand-600">
            <p class="text-caption text-text-secondary">{{ 'Skema Formulir' }}</p>
            <p class="mt-1 text-heading-sm">{{ $templates->count() }} formulir aktif</p>
            <p class="mt-1 text-body-sm text-text-muted">{{ ' versioning dan penerbitan' }}</p>
        </a>

        <a href="{{ route('admin.webhooks.index') }}" class="card p-5 transition-colors duration-150 hover:border-brand-600">
            <p class="text-caption text-text-secondary">{{ 'Endpoint Webhook' }}</p>
            <p class="mt-1 text-heading-sm">{{ 'Integrator' }}</p>
            <p class="mt-1 text-body-sm text-text-muted">{{ ' HMAC-SHA256 per payload' }}</p>
        </a>

        <a href="{{ route('admin.bpjs.simulator') }}" class="card p-5 transition-colors duration-150 hover:border-brand-600">
            <p class="text-caption text-text-secondary">{{ 'Simulator BPJS VClaim' }}</p>
            <p class="mt-1 text-heading-sm">{{ 'SEP Insert' }}</p>
            <p class="mt-1 text-body-sm text-text-muted">{{ ' user-flow.md Flow 4' }}</p>
        </a>

        <a href="{{ route('admin.fhir.explorer') }}" class="card p-5 transition-colors duration-150 hover:border-brand-600">
            <p class="text-caption text-text-secondary">{{ 'Explorer SatuSehat' }}</p>
            <p class="mt-1 text-heading-sm">{{ 'FHIR R4' }}</p>
            <p class="mt-1 text-body-sm text-text-muted">{{ ' FR-16 s.d. FR-18' }}</p>
        </a>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <p class="text-caption text-text-secondary">{{ 'Pasien terdaftar' }}</p>
            <p class="mt-1 font-display-md">{{ number_format($patientCount, 0, ',', '.') }}</p>
        </div>
        <div class="card p-5">
            <p class="text-caption text-text-secondary">{{ 'Encounter' }}</p>
            <p class="mt-1 font-display-md">{{ number_format($encounterCount, 0, ',', '.') }}</p>
        </div>
    </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Skema Formulir')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6">
        <h1 class="text-display-lg">Manajemen Skema Formulir</h1>
        <p class="text-body-sm text-text-muted">
            Menambah atau mengubah formulir tidak memerlukan migrasi basis data.
            Setiap perubahan menerbitkan versi baru; versi lama tetap terbaca untuk
            rekam medis yang dibuat sebelumnya.
        </p>
    </header>

    @foreach ($templates as $code => $versions)
        @php($active = $versions->firstWhere('is_active', true))
        <section class="card mb-6">
            <header class="flex flex-wrap items-center gap-3 border-b border-border-subtle p-4">
                <div>
                    <h2 class="text-heading-md">{{ $active?->name ?? $code }}</h2>
                    <p class="text-body-sm text-text-muted">
                        <span class="font-mono-sm">{{ $code }}</span>
                        &middot; {{ $versions->count() }} versi
                        @if ($active)
                            &middot; aktif: <strong class="font-mono-sm">v{{ $active->version }}</strong>
                        @endif
                    </p>
                </div>
                <a href="{{ route('admin.schemas.edit', $active ?? $versions->first()) }}"
                   class="btn-primary ml-auto">Edit / Terbitkan Versi Baru</a>
            </header>

            <table class="w-full border-collapse text-body-sm">
                <caption class="sr-only">Riwayat versi untuk {{ $code }}</caption>
                <thead>
                    <tr class="border-b border-border-subtle bg-surface-subtle text-left text-caption text-text-secondary">
                        <th scope="col" class="px-4 py-2">Versi</th>
                        <th scope="col" class="px-4 py-2">Status</th>
                        <th scope="col" class="px-4 py-2">Field</th>
                        <th scope="col" class="px-4 py-2">Oleh</th>
                        <th scope="col" class="px-4 py-2">Dibuat</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($versions as $version)
                        <tr class="border-b border-border-subtle last:border-0">
                            <td class="px-4 py-2 font-mono-sm">v{{ $version->version }}</td>
                            <td class="px-4 py-2">
                                <span class="pill {{ $version->is_active ? 'bg-success-50 text-success-700' : 'bg-surface-subtle text-text-secondary' }}">
                                    {{ $version->is_active ? 'Aktif' : 'Arsip' }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ count($version->fields()) }}</td>
                            <td class="px-4 py-2">{{ $version->author?->name ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono-sm">{{ $version->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.schemas.edit', $version) }}"
                                   class="btn-secondary px-3 py-1 text-body-sm">Lihat</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</div>
@endsection

@extends('layouts.app')

@section('title', 'Worklist DPJP')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6">
        <h1 class="text-display-lg">Worklist DPJP</h1>
        <p class="text-body-sm text-text-muted">
            Kunjungan rawat inap yang menunggu asesmen medis dan penandatanganan.
        </p>
    </header>

    <div class="card overflow-x-auto">
        <table class="w-full border-collapse text-body-sm">
            <caption class="sr-only">Kunjungan yang menunggu asesmen dokter</caption>
            <thead>
                <tr class="border-b border-border-subtle bg-surface-subtle text-left text-caption text-text-secondary">
                    <th scope="col" class="px-4 py-2">No. Kunjungan</th>
                    <th scope="col" class="px-4 py-2">Pasien</th>
                    <th scope="col" class="px-4 py-2">Usia / JK</th>
                    <th scope="col" class="px-4 py-2">Unit</th>
                    <th scope="col" class="px-4 py-2">Masuk</th>
                    <th scope="col" class="px-4 py-2">Formulir</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($encounters as $encounter)
                    @php($locked = $encounter->submissions->contains(fn ($s) => $s->isLocked()))
                    <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-subtle">
                        <td class="px-4 py-2 font-mono-sm">{{ $encounter->encounter_number }}</td>
                        <td class="px-4 py-2">{{ $encounter->patient?->full_name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ $encounter->patient?->ageInYears() }} thn /
                            {{ $encounter->patient?->gender->shortLabel() }}
                        </td>
                        <td class="px-4 py-2">{{ $encounter->service_unit }}</td>
                        <td class="px-4 py-2 font-mono-sm">{{ $encounter->admission_time->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            <span class="pill {{ $locked ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-600' }}">
                                {{ $locked ? 'Sudah ditandatangani' : 'Menunggu DPJP' }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('portal.doctor.assessment', $encounter) }}" class="btn-secondary px-3 py-1 text-body-sm">
                                {{ $locked ? 'Lihat' : 'Asesmen' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="border-2 border-dashed border-border-strong p-8 text-center text-body-sm text-text-muted">
                            Tidak ada kunjungan yang menunggu asesmen.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
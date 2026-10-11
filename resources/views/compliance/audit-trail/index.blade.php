@extends('layouts.app')

@section('title', 'Jejak Audit')

@section('content')

    <div class="mx-auto max-w-[1600px]">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-display-lg">Jejak Audit Rekam Medis</h1>
                <p class="text-body-sm text-text-muted">
                    Setiap akses, perubahan, dan ekspor tercatat permanen sesuai Permenkes No. 24/2022.
                    Tabel <code class="font-mono">audit_logs</code> menolak pembaruan dan penghapusan di level basis data.
                </p>
            </div>

            <a href="{{ route('compliance.audit.export', request()->query()) }}" class="btn-primary">
                Ekspor Laporan Audit (PDF)
            </a>
        </header>

        {{-- FR-14 filters: date range, acting user, action type, patient RM. --}}
        <form method="GET" action="{{ route('compliance.audit.index') }}"
              class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label for="from" class="mb-1 block text-caption text-text-secondary">Dari tanggal</label>
                <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="field">
            </div>
            <div>
                <label for="to" class="mb-1 block text-caption text-text-secondary">Sampai tanggal</label>
                <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="field">
            </div>
            <div>
                <label for="user_id" class="mb-1 block text-caption text-text-secondary">Pengguna</label>
                <select id="user_id" name="user_id" class="field">
                    <option value="">Semua pengguna</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') === $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="action" class="mb-1 block text-caption text-text-secondary">Aksi</label>
                <select id="action" name="action" class="field">
                    @foreach ($actions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['action'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="medical_record_number" class="mb-1 block text-caption text-text-secondary">No. Rekam Medis</label>
                <input id="medical_record_number" type="text" name="medical_record_number"
                       value="{{ $filters['medical_record_number'] ?? '' }}"
                       placeholder="00-48-21" class="field font-mono-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" id="audit-filter-apply" class="btn-primary flex-1">Terapkan</button>
                <a href="{{ route('compliance.audit.index') }}" class="btn-secondary">Reset</a>
            </div>
        </form>

        <div class="card overflow-x-auto" tabindex="0">
            <table class="w-full border-collapse text-body-sm">
                <caption class="sr-only">Daftar akses rekam medis yang tercatat</caption>
                <thead>
                    <tr class="border-b border-border-subtle bg-surface-subtle text-left text-caption text-text-secondary">
                        <th scope="col" class="px-4 py-2">Waktu (WIB)</th>
                        <th scope="col" class="px-4 py-2">Pengguna</th>
                        <th scope="col" class="px-4 py-2">Peran</th>
                        <th scope="col" class="px-4 py-2">Aksi</th>
                        <th scope="col" class="px-4 py-2">Resource</th>
                        <th scope="col" class="px-4 py-2">IP</th>
                        <th scope="col" class="px-4 py-2">Checksum SHA-256</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-subtle">
                            <td class="whitespace-nowrap px-4 py-2 font-mono-sm">{{ $entry->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-2">{{ $entry->user?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-text-secondary">{{ $entry->role_name }}</td>
                            <td class="px-4 py-2">
                                @php($badge = match ($entry->action) {
                                    \App\Enums\AuditAction::Create => 'bg-success-50 text-success-700',
                                    \App\Enums\AuditAction::Update => 'bg-warning-50 text-warning-600',
                                    \App\Enums\AuditAction::Delete => 'bg-danger-50 text-danger-600',
                                    \App\Enums\AuditAction::Lock => 'bg-brand-50 text-brand-700',
                                    \App\Enums\AuditAction::Export => 'bg-surface-subtle text-text-secondary',
                                    default => 'bg-brand-50 text-brand-700',
                                })
                                <span class="pill {{ $badge }}">{{ $entry->action->value }}</span>
                            </td>
                            <td class="px-4 py-2 font-mono-sm text-text-secondary">{{ class_basename($entry->resource_type) }}</td>
                            <td class="px-4 py-2 font-mono-sm text-text-secondary">{{ $entry->ip_address }}</td>
                            <td class="px-4 py-2 font-mono-sm text-text-muted" title="{{ $entry->payload_checksum }}">
                                {{ substr($entry->payload_checksum, 0, 16) }}…
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="border-2 border-dashed border-border-strong p-8 text-center text-body-sm text-text-muted">
                                Tidak ada catatan audit yang cocok dengan filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </div>

@endsection
@php
    $user = auth()->user();
    $currentRole = $user?->getRoleNames()->first() ?? '-';
@endphp

{{-- ui-spec.md: app bar is a fixed 56px. --}}
<header class="fixed inset-x-0 top-0 z-30 flex h-14 items-center gap-4 border-b border-border-subtle bg-surface-base px-4">
    <a href="{{ route('portal.home') }}" class="flex items-center gap-2 rounded-md">
        <span class="grid h-8 w-8 place-items-center rounded-md bg-brand-600 text-body-sm font-bold text-white">KF</span>
        <span class="text-heading-sm text-text-primary">KlinikForm <span class="text-text-muted">SIMRS</span></span>
    </a>

    <div class="ml-auto flex items-center gap-3">
        <span class="hidden text-caption text-text-muted sm:inline">
            {{ $user?->name ?? 'Tamu' }}
            @if ($currentRole !== '-')
                <span class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 font-medium text-brand-700">{{ $currentRole }}</span>
            @endif
        </span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-secondary px-3 py-1.5 text-body-sm">Keluar</button>
        </form>
    </div>
</header>
@php
    use Database\Seeders\RoleSeeder;

    $user = auth()->user();

    // Only the destinations the signed-in role can actually reach, so the
    // navigation never advertises a screen that would answer 403.
    $links = array_values(array_filter([
        $user?->can('createAny', App\Models\FormSubmission::class) ? [
            'label' => 'Intake Triase',
            'href' => route('portal.nurse.intake'),
            'active' => 'portal.nurse.*',
        ] : null,
        $user?->hasRole(RoleSeeder::DPJP) ? [
            'label' => 'Worklist DPJP',
            'href' => route('portal.doctor.worklist'),
            'active' => 'portal.doctor.*',
        ] : null,
        $user?->can('manage', App\Models\FormTemplate::class) ? [
            'label' => 'Skema Formulir',
            'href' => route('admin.schemas.index'),
            'active' => 'admin.schemas.*',
        ] : null,
        $user?->can('viewAny', App\Models\AuditLog::class) ? [
            'label' => 'Jejak Audit',
            'href' => route('compliance.audit.index'),
            'active' => 'compliance.audit.*',
        ] : null,
        $user?->can('manage', App\Models\WebhookEndpoint::class) ? [
            'label' => 'Endpoint Webhook',
            'href' => route('admin.webhooks.index'),
            'active' => 'admin.webhooks.*',
        ] : null,
        // security.md §MFA: enrolment is self-service, so every role gets it.
        $user !== null ? [
            'label' => 'Keamanan Akun',
            'href' => route('portal.two-factor.show'),
            'active' => 'portal.two-factor.*',
        ] : null,
    ]));
@endphp

{{-- ui-spec.md: 240px fixed navigation, collapsing to a slide-over below
     the tablet-portrait breakpoint. --}}
<nav class="fixed bottom-0 left-0 top-14 z-20 hidden w-60 flex-col border-r border-border-subtle bg-surface-base lg:flex"
     aria-label="Navigasi utama">
    <div class="flex flex-col gap-1 p-3">
        @foreach ($links as $link)
            @php($isActive = request()->routeIs($link['active']))
            <a href="{{ $link['href'] }}"
               class="rounded-md px-3 py-2 text-body-md transition-colors duration-150 {{ $isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-text-secondary hover:bg-surface-subtle' }}"
               @if ($isActive) aria-current="page" @endif>
                {{ $link['label'] }}
            </a>
        @endforeach
    </div>

    <div class="mt-auto border-t border-border-subtle p-3 text-caption text-text-muted">
        Data terlindungi AES-256-GCM.<br>Akses tercatat pada <code class="font-mono">audit_logs</code>.
    </div>
</nav>
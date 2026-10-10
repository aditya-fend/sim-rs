<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'KlinikForm SIMRS') — KlinikForm SIMRS</title>

    {{-- NFR-07 / ui-spec.md: clinical data entry is never a read-only app. --}}
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-canvas-bg text-text-primary antialiased">

{{-- Keyboard entry point per ui-spec.md: the first focusable control is the
     patient lookup, never a decorative link. --}}
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
    Lewati ke konten utama
</a>

@include('layouts.partials.app-bar')

<div class="flex min-h-[calc(100vh-56px)]">
    @include('layouts.partials.side-nav')

    <div class="flex min-w-0 flex-1 flex-col">
        @yield('patient-strip')

        <main id="main-content" tabindex="-1" class="min-w-0 flex-1 px-4 py-6 focus:outline-none sm:px-6">
            @yield('content')
        </main>

        <footer class="border-t border-border-subtle px-6 py-3 text-caption text-text-muted">
            KlinikForm SIMRS &middot; Permenkes No. 24/2022 &middot;
            <span class="font-mono">{{ now()->format('d/m/Y H:i') }} WIB</span>
        </footer>
    </div>
</div>

@stack('modals')

@stack('scripts')

<div id="toast-region"
     role="status"
     aria-live="polite"
     class="pointer-events-none fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>

</body>
</html>
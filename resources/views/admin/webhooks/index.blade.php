@extends('layouts.app')

@section('title', 'Endpoint Webhook')

@section('content')
<div class="mx-auto max-w-[1600px]">
    <header class="mb-6 flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-display-lg">{{ 'Endpoint Webhook' }}</h1>
            <p class="text-body-sm text-text-muted">
                architecture.md §Integration — setiap payload ditandatangani HMAC-SHA256 dengan secret endpoint.
            </p>
        </div>
    </header>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-success-700 bg-success-50 px-4 py-2 text-body-sm text-success-700">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->any())
        <ul role="alert" class="mb-4 rounded-md border border-danger-600 bg-danger-50 px-4 py-2 text-body-sm text-danger-600">
            @foreach ($errors->all() as $error)
                <li>{{ is_array($error) ? implode(' ', $error) : $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="grid gap-6 xl:grid-cols-12">
        <div class="xl:col-span-7">
            <div class="card p-5">
                <h2 class="text-heading-sm">{{ 'Daftar Endpoint' }}</h2>

                @if ($endpoints->isEmpty())
                    <p class="mt-2 text-body-sm text-text-muted">
                        {{ 'Belum ada integrator yang terdaftar.' }}
                    </p>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($endpoints as $endpoint)
                            <form method="POST" action="{{ route('admin.webhooks.update', $endpoint) }}"
                                  class="rounded-md border border-border-subtle p-4">
                                @csrf
                                @method('PUT')

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-caption text-text-secondary"
                                               for="label-{{ $endpoint->id }}">{{ 'Label' }}</label>
                                        <input id="label-{{ $endpoint->id }}" name="label" type="text" required
                                               maxlength="100" value="{{ $endpoint->label }}" class="field">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-caption text-text-secondary"
                                               for="url-{{ $endpoint->id }}">{{ 'URL' }}</label>
                                        <input id="url-{{ $endpoint->id }}" name="url" type="url" required
                                               maxlength="500" value="{{ $endpoint->url }}" class="field">
                                    </div>
                                </div>

                                <fieldset class="mt-3">
                                    <legend class="mb-1 text-caption text-text-secondary">{{ 'Event' }}</legend>
                                    <div class="flex flex-wrap gap-3">
                                        @foreach ($events as $event)
                                            <label class="flex items-center gap-1.5 text-body-sm text-text-secondary">
                                                <input type="checkbox" name="events[]" value="{{ $event }}"
                                                       @checked(in_array($event, $endpoint->events, true))
                                                       class="h-4 w-4 rounded-sm accent-brand-600">
                                                <span class="font-mono-sm">{{ $event }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <label class="flex items-center gap-2 text-body-sm text-text-secondary">
                                        <input type="checkbox" name="is_active" value="1"
                                               @checked($endpoint->is_active)
                                               class="h-4 w-4 rounded-sm accent-brand-600">
                                        Aktif
                                    </label>

                                    <div class="flex gap-2">
                                        <button type="submit" class="btn-secondary">Simpan</button>
                                    </div>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.webhooks.destroy', $endpoint) }}"
                                  class="flex justify-end">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost text-danger-600">Hapus endpoint</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <aside class="xl:col-span-5">
            <div class="card p-5">
                <h2 class="text-heading-sm">{{ 'Daftarkan Integrator Baru' }}</h2>

                <form method="POST" action="{{ route('admin.webhooks.store') }}" class="mt-4">
                    @csrf

                    <label for="new-label" class="mb-1 block text-caption text-text-secondary">{{ 'Label' }}</label>
                    <input id="new-label" name="label" type="text" required maxlength="100" class="field">

                    <label for="new-url" class="mt-3 block text-caption text-text-secondary">{{ 'URL endpoint' }}</label>
                    <input id="new-url" name="url" type="url" required maxlength="500"
                           placeholder="https://integrator.example/hooks/simrs" class="field">

                    <fieldset class="mt-3">
                        <legend class="mb-1 text-caption text-text-secondary">{{ 'Event yang berlangganan' }}</legend>
                        <div class="space-y-1">
                            @foreach ($events as $event)
                                <label class="flex items-center gap-2 text-body-sm text-text-secondary">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" required
                                           @checked($loop->first) class="h-4 w-4 rounded-sm accent-brand-600">
                                    <span class="font-mono-sm">{{ $event }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <p class="mt-3 text-body-sm text-text-muted">
                        {{ 'Secret HMAC dibuat sistem dan hanya ditampilkan sekali. Integrator wajib menyimpannya untuk memverifikasi tanda tangan setiap payload.' }}
                    </p>

                    <button type="submit" class="btn-primary mt-4">Daftarkan</button>
                </form>
            </div>
        </aside>
    </div>
</div>
@endsection

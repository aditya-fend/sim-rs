@extends('layouts.app')

@section('title', 'Edit Skema '.$template->code)

@section('content')
<div class="mx-auto max-w-[1600px]"
     x-data="schemaEditor(@js($json), @js($template->id))"
     x-cloak>

    <header class="mb-6 flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-display-lg">{{ $template->name }}</h1>
            <p class="text-body-sm text-text-muted">
                <span class="font-mono-sm">{{ $template->code }}</span>
                &middot; Anda sedang menyunting v{{ $template->version }}
                &rarr; akan terbit sebagai
                <strong class="font-mono-sm" x-text="'v' + (nextVersion)"></strong>
            </p>
        </div>

        <a href="{{ route('admin.schemas.index') }}" class="btn-secondary ml-auto">Kembali</a>
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
        <div class="xl:col-span-8">
            <form method="POST" action="{{ route('admin.schemas.publish', $template) }}"
                  x-on:submit.prevent="publish()">
                @csrf

                <div class="mb-4">
                    <label for="name" class="mb-1 block text-body-sm font-medium text-text-secondary">Nama formulir</label>
                    <input id="name" name="name" type="text" x-model="name" class="field" required>
                </div>

                <label for="schema_definition" class="mb-1 block text-body-sm font-medium text-text-secondary">
                    Definisi skema (JSON)
                </label>
                <textarea id="schema_definition" name="schema_definition" rows="28"
                          x-model="json" spellcheck="false"
                          class="field font-mono-sm leading-relaxed"></textarea>
                <input type="hidden" name="baseline" :value="baseline">

                <label class="mt-3 flex items-center gap-2 text-body-sm text-text-secondary">
                    <input type="checkbox" name="activate" value="1" x-model="activate" class="h-4 w-4 rounded-sm accent-brand-600">
                    Aktifkan versi ini untuk intake baru
                </label>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" class="btn-secondary" x-on:click="validate()">Uji Validasi Skema</button>
                    <button type="submit" class="btn-primary" x-bind:disabled="publishing || !valid">
                        <span x-show="!publishing">Terbitkan Versi Baru</span>
                        <span x-show="publishing">Menerbitkan…</span>
                    </button>
                </div>
            </form>
        </div>

        <aside class="xl:col-span-4">
            {{-- Validation result, announced rather than merely coloured. --}}
            <div class="card p-4" role="status" aria-live="polite">
                <p class="text-heading-sm">Hasil Validasi</p>

                <p x-show="!summary" class="mt-2 text-body-sm text-text-muted">
                    Belum divalidasi. Periksa sintaks dan semantik skema sebelum menerbitkannya.
                </p>

                <template x-if="summary">
                    <div class="mt-2">
                        <p class="text-body-sm" :class="valid ? 'text-success-700' : 'text-danger-600'"
                           x-text="summary"></p>

                        <ul x-show="errors.length" class="mt-2 list-disc space-y-1 pl-5 text-body-sm text-danger-600">
                            <template x-for="error in errors" :key="error">
                                <li x-text="error"></li>
                            </template>
                        </ul>

                        <div x-show="newFields.length" class="mt-2">
                            <p class="text-caption text-text-secondary">Field baru terdeteksi:</p>
                            <p class="font-mono-sm text-text-primary" x-text="newFields.join(', ')"></p>
                        </div>
                    </div>
                </template>
            </div>

            <div class="card mt-4 p-4">
                <p class="text-heading-sm">Riwayat Versi</p>
                <ul class="mt-2 space-y-1 text-body-sm">
                    @foreach ($history as $version)
                        <li class="flex justify-between border-b border-border-subtle py-1">
                            <a class="font-mono-sm text-brand-700 hover:underline"
                               href="{{ route('admin.schemas.edit', $version) }}">v{{ $version->version }}</a>
                            <span class="{{ $version->is_active ? 'text-success-700' : 'text-text-muted' }}">
                                {{ $version->is_active ? 'aktif' : 'arsip' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('schemaEditor', (json, currentVersion) => ({
            json,
            baseline: json,
            nextVersion: currentVersion + 1,
            name: document.getElementById('name')?.value ?? '',
            valid: false,
            publishing: false,
            errors: [],
            newFields: [],
            summary: null,

            async validate() {
                const response = await fetch('{{ route('admin.schemas.validate') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        schema_definition: this.json,
                        baseline: this.baseline,
                    }),
                });

                const body = await response.json();

                this.valid = body.valid === true;
                this.errors = body.errors ?? [];
                this.newFields = body.new_fields ?? [];
                this.summary = body.summary ?? (body.errors ?? ['JSON tidak valid.']).join(' ');
            },

            async publish() {
                this.publishing = true;

                try {
                    const form = this.$root.querySelector('form');
                    form.querySelector('#schema_definition').value = this.json;
                    form.submit();
                } finally {
                    this.publishing = false;
                }
            },
        }));
    });
</script>
@endpush
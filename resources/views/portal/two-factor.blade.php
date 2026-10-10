@extends('layouts.app')

@section('title', 'Autentikasi Dua Faktor')

@section('content')
<div class="mx-auto max-w-2xl">
    <header class="mb-6">
        <h1 class="text-display-lg">{{ 'Autentikasi Dua Faktor' }}</h1>
        <p class="text-body-sm text-text-muted">
            security.md §MFA — aplikasi autentikator seperti Google Authenticator.
        </p>
    </header>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-success-700 bg-success-50 px-4 py-2 text-body-sm text-success-700">
            {{ session('status') }}
        </p>
    @endif

    <div class="card p-5">
        @if ($confirmed)
            <p class="text-body-sm text-success-700" role="status">
                {{ 'Autentikasi dua faktor aktif. Kode akan diminta setiap kali Anda masuk.' }}
            </p>

            <form method="POST" action="{{ route('portal.two-factor.destroy') }}" class="mt-4">
                @csrf
                @method('DELETE')

                <label for="current_password" class="mb-1 block text-body-sm font-medium text-text-secondary">
                    {{ 'Kata sandi saat ini' }}
                </label>
                <input id="current_password" name="current_password" type="password" required
                       autocomplete="current-password" class="field max-w-sm">

                <p class="mt-3 text-body-sm text-text-muted">
                    {{ 'Meminta ulang kata sandi mencegah sesi yang dibajak menonaktifkan MFA.' }}
                </p>

                <button type="submit" class="btn-danger mt-4">Nonaktifkan MFA</button>
            </form>
        @else
            <p class="text-body-sm text-text-muted">
                {{ 'Belum aktif. Pindai kode QR dengan aplikasi autentikator, lalu masukkan kode yang dihasilkan untuk mengonfirmasi.' }}
            </p>

            <form method="POST" action="{{ route('portal.two-factor.start') }}" class="mt-4">
                @csrf
                <button type="submit" class="btn-primary">Mulai Enrolment</button>
            </form>

            @if (session('provisioning_uri'))
                <div class="mt-5 border-t border-border-subtle pt-5">
                    <p class="text-caption text-text-secondary">
                        {{ 'Tambahkan akun ini secara manual bila QR tidak dapat dipindai:' }}
                    </p>
                    <p class="mt-1 break-all font-mono-sm text-text-primary">
                        {{ session('provisioning_uri') }}
                    </p>
                </div>
            @endif

            @if ($errors->any())
                <ul role="alert" class="mt-4 rounded-md border border-danger-600 bg-danger-50 px-4 py-2 text-body-sm text-danger-600">
                    @foreach ($errors->all() as $error)
                        <li>{{ is_array($error) ? implode(' ', $error) : $error }}</li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('portal.two-factor.confirm') }}" class="mt-5 border-t border-border-subtle pt-5">
                @csrf

                <label for="code" class="mb-1 block text-body-sm font-medium text-text-secondary">
                    {{ 'Kode dari aplikasi autentikator' }}
                </label>
                <input id="code" name="code" type="text" inputmode="numeric" required
                       pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                       class="field max-w-[12rem] font-mono-md">

                <button type="submit" class="btn-primary mt-4">Konfirmasi</button>
            </form>
        @endif
    </div>

    {{-- Recovery codes are hashed on the way in and shown only once. --}}
    @if (session('recovery_codes'))
        <div class="card mt-6 border-warning-600 p-5">
            <p class="text-heading-sm text-warning-600">{{ 'Kode Pemulihan' }}</p>
            <p class="mt-1 text-body-sm text-text-muted">
                {{ 'Simpan di tempat aman. Setiap kode hanya dapat dipakai sekali dan tidak akan ditampilkan lagi.' }}
            </p>
            <ul class="mt-3 grid gap-1 font-mono-md text-text-primary sm:grid-cols-2">
                @foreach (session('recovery_codes') as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endsection

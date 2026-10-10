<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — KlinikForm SIMRS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full place-items-center bg-canvas-bg px-4">

<main class="w-full max-w-sm">
    <div class="mb-6 text-center">
        <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-lg bg-brand-600 text-heading-md font-bold text-white">KF</span>
        <h1 class="text-heading-md">KlinikForm SIMRS</h1>
        <p class="text-body-sm text-text-muted">Sistem Informasi Manajemen Rumah Sakit</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6">
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded-md border border-danger-600 bg-danger-50 px-3 py-2 text-body-sm text-danger-600">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label for="email" class="mb-1 block text-body-sm font-medium text-text-secondary">Email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="username"
                   value="{{ old('email') }}" class="field @error('email') field-error @enderror"
                   @error('email') aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" class="mt-1 text-body-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-body-sm font-medium text-text-secondary">Kata Sandi</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="field @error('password') field-error @enderror">
            @error('password')<p class="mt-1 text-body-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="totp" class="mb-1 block text-body-sm font-medium text-text-secondary">
                Kode Verifikasi <span class="font-normal text-text-muted">(jika diaktifkan)</span>
            </label>
            <input id="totp" name="totp" type="text" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]*" maxlength="10" placeholder="000000"
                   class="field font-mono-sm @error('totp') field-error @enderror">
            @error('totp')<p id="totp-error" class="mt-1 text-body-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <label class="flex items-center gap-2 text-body-sm text-text-secondary">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded-sm border-border-strong">
            Ingat perangkat ini
        </label>

        <button type="submit" class="btn-primary w-full">Masuk</button>
    </form>

    <p class="mt-4 text-center text-caption text-text-muted">
        Setiap akses ke rekam medis dicatat pada jejak audit Permenkes No. 24/2022.
    </p>
</main>

</body>
</html>
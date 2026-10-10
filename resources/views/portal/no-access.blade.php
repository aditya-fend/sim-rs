@extends('layouts.app')

@section('title', 'Akses Terbatas')

@section('content')
    <div class="card mx-auto max-w-md p-8 text-center">
        <h1 class="text-heading-md">Akses dibatasi</h1>
        <p class="mt-2 text-body-md text-text-secondary">
            Akun <strong>{{ $user->name }}</strong> belum memiliki peran klinis yang
            Terdaftar. Hubungi administrator SIMRS.
        </p>
        <p class="mt-4 text-body-sm text-text-muted">
            {{ 'Akses dibatasi. Anda tidak memiliki wewenang untuk mengisi asesmen medis DPJP.' }}
        </p>
        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button class="btn-secondary">Keluar</button>
        </form>
    </div>
@endsection

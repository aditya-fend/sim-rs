{{-- A role with exactly one obvious place to work should not have to pick it
     from a menu, and should not render a menu of things it cannot use. --}}
@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
    <div class="mx-auto max-w-md text-center">
        <p class="text-body-md text-text-muted">Mengalihkan ke ruang kerja Anda…</p>
        <p class="mt-2"><a href="{{ $destination }}" class="btn-primary">Lanjut</a></p>
        <noscript><meta http-equiv="refresh" content="0; url={{ $destination }}"></noscript>
    </div>
@endsection

@push('scripts')
    <script>window.location.replace(@json($destination));</script>
@endpush

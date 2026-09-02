<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VAT-MSA') }} @hasSection('title')&mdash; @yield('title')@endif</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- WCAG 2.1 SC 2.4.1 "Bypass Blocks" -- lets keyboard/screen-reader users
         skip the repeated nav on every page. Visually hidden until focused. --}}
    <a class="visually-hidden-focusable skip-link" href="#main-content">Skip to main content</a>

    @auth
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark" aria-label="Primary">
            <div class="container-fluid">
                <a class="navbar-brand" href="{{ route('dashboard') }}">VAT-MSA</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navMain">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
                        </li>
                        @can('permission', 'invoices:read')
                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('invoices.index') }}" @if (request()->routeIs('invoices.*')) aria-current="page" @endif>Invoices</a>
                            </li>
                        @endcan
                    </ul>
                    <span class="navbar-text text-white-50 me-3">
                        {{ auth()->user()->name }}
                        <span class="badge bg-secondary ms-1">{{ auth()->user()->role }}</span>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm">Log out</button>
                    </form>
                </div>
            </div>
        </nav>
    @endauth

    <main id="main-content" class="container-fluid py-4" tabindex="-1">
        @if (session('status'))
            <div class="alert alert-success" role="alert">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>

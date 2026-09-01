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
    @auth
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container-fluid">
                <a class="navbar-brand" href="{{ route('dashboard') }}">VAT-MSA</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navMain">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a></li>
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

    <main class="container-fluid py-4">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>

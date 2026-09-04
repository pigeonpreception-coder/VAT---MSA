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

    <div class="d-flex flex-column flex-md-row">
        @auth
            {{-- Below md: a slim top bar with the brand and a toggle button
                 that reveals the nav, stacked full-width above the content
                 (never squeezed side-by-side -- this app is verified down to
                 a 320px viewport with no horizontal scroll, so the sidebar
                 must not permanently steal width there). At md and up, this
                 bar is hidden and the sidebar itself carries the brand,
                 always visible, fixed-width, to the left of the content. --}}
            <nav class="navbar navbar-dark bg-dark d-md-none" aria-label="Primary">
                <div class="container-fluid">
                    <a class="navbar-brand" href="{{ route('dashboard') }}">VAT-MSA</a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarNav" aria-controls="sidebarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>

            <nav id="sidebarNav" class="collapse d-md-block bg-dark sidebar" aria-label="Primary">
                <div class="d-flex flex-column h-100 p-3">
                    <a class="navbar-brand text-white d-none d-md-inline-block mb-3" href="{{ route('dashboard') }}">VAT-MSA</a>
                    {{-- Active state uses text-bg-primary, not nav-pills' own
                         .active (Bootstrap primary-blue bg + white text with
                         no documented auto-contrast guarantee) -- text-bg-*
                         is this app's own established, verified-WCAG-AA
                         pairing (see <x-status-badge>'s own doc comment). --}}
                    <ul class="nav nav-pills flex-column mb-auto">
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('dashboard') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
                        </li>
                        @can('permission', 'invoices:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('invoices.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('invoices.index') }}" @if (request()->routeIs('invoices.*')) aria-current="page" @endif>Invoices</a>
                            </li>
                        @endcan
                        @can('permission', 'returns:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('vat-periods.*', 'vat-returns.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('vat-periods.index') }}" @if (request()->routeIs('vat-periods.*', 'vat-returns.*')) aria-current="page" @endif>VAT Returns</a>
                            </li>
                        @endcan
                        @can('permission', 'refunds:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('refunds.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('refunds.index') }}" @if (request()->routeIs('refunds.*')) aria-current="page" @endif>Refunds</a>
                            </li>
                        @endcan
                        @can('permission', 'risk:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('risk-indicators.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('risk-indicators.index') }}" @if (request()->routeIs('risk-indicators.*')) aria-current="page" @endif>Risk Indicators</a>
                            </li>
                        @endcan
                        @can('permission', 'compliance:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('audit-cases.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('audit-cases.index') }}" @if (request()->routeIs('audit-cases.*')) aria-current="page" @endif>Audit Cases</a>
                            </li>
                        @endcan
                        @can('permission', 'compliance:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('disputes.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('disputes.index') }}" @if (request()->routeIs('disputes.*')) aria-current="page" @endif>Disputes</a>
                            </li>
                        @endcan
                        @can('permission', 'compliance:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('obligations.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('obligations.index') }}" @if (request()->routeIs('obligations.*')) aria-current="page" @endif>Obligations</a>
                            </li>
                        @endcan
                        @can('permission', 'identity:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('organisations.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('organisations.index') }}" @if (request()->routeIs('organisations.*')) aria-current="page" @endif>Organisations</a>
                            </li>
                        @endcan
                        @can('permission', 'parties:manage')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('business-parties.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('business-parties.index') }}" @if (request()->routeIs('business-parties.*')) aria-current="page" @endif>Business Parties</a>
                            </li>
                        @endcan
                        @can('permission', 'compliance:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('compliance-overview.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('compliance-overview.index') }}" @if (request()->routeIs('compliance-overview.*')) aria-current="page" @endif>Compliance Overview</a>
                            </li>
                        @endcan
                        @can('permission', 'licensing:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('licensing.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('licensing.index') }}" @if (request()->routeIs('licensing.*')) aria-current="page" @endif>Licensing</a>
                            </li>
                        @endcan
                        @can('permission', 'commercial:read')
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('quotations.*') ? 'text-bg-primary' : 'text-white' }}" href="{{ route('quotations.index') }}" @if (request()->routeIs('quotations.*')) aria-current="page" @endif>Quotations</a>
                            </li>
                        @endcan
                    </ul>
                    <hr class="text-white-50">
                    <div class="text-white-50 small mb-2">
                        {{ auth()->user()->name }}
                        <span class="badge bg-secondary ms-1">{{ auth()->user()->role }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm w-100">Log out</button>
                    </form>
                </div>
            </nav>
        @endauth

        <main id="main-content" class="flex-grow-1 container-fluid py-4" tabindex="-1">
            @if (session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>

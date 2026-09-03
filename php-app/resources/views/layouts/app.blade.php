<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VAT-MSA') }} @hasSection('title')&mdash; @yield('title')@endif</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@auth has-sidebar @endauth">
    {{-- WCAG 2.1 SC 2.4.1 "Bypass Blocks" -- lets keyboard/screen-reader users
         skip the repeated nav on every page. Visually hidden until focused. --}}
    <a class="visually-hidden-focusable skip-link" href="#main-content">Skip to main content</a>

    @auth
        {{-- Below the lg breakpoint the sidebar below becomes an off-canvas
             drawer (Bootstrap's own .offcanvas-lg responsive behaviour) --
             this slim bar is its toggle and stays in the normal document
             flow, so it needs no fixed positioning of its own. --}}
        <nav class="navbar navbar-dark d-lg-none mobile-topbar px-3" aria-label="Primary">
            <a class="navbar-brand" href="{{ route('dashboard') }}">VAT-MSA</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </nav>

        {{-- Deliberately offcanvas-lg + offcanvas-start only -- NOT the
             plain .offcanvas class too. Bootstrap's plain .offcanvas
             carries its own unconditional (non-responsive) drawer/
             transform rules that would fight offcanvas-lg's >=992px
             "always visible, normal column" behaviour if both were
             present together (confirmed live: with .offcanvas added,
             the sidebar stayed translateX(-100%)/visibility:hidden even
             at desktop widths where offcanvas-lg's own media query
             correctly matched). --}}
        <div class="offcanvas-start offcanvas-lg sidebar" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">
            <div class="offcanvas-header d-lg-none">
                <span class="offcanvas-title h5 mb-0" id="sidebarLabel">VAT-MSA</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-0">
                <a class="sidebar-brand d-none d-lg-block" href="{{ route('dashboard') }}">VAT-MSA</a>
                <ul class="nav nav-pills flex-column sidebar-nav flex-grow-1">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('portals.index') }}" @if (request()->routeIs('portals.*')) aria-current="page" @endif>Portals</a>
                    </li>
                    @can('permission', 'invoices:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('invoices.index') }}" @if (request()->routeIs('invoices.*')) aria-current="page" @endif>Invoices</a>
                        </li>
                    @endcan
                    @can('permission', 'cases:manage')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('cases.index') }}" @if (request()->routeIs('cases.*')) aria-current="page" @endif>Audit cases</a>
                        </li>
                    @endcan
                    @can('permission', 'compliance:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('compliance.index') }}" @if (request()->routeIs('compliance.*')) aria-current="page" @endif>Compliance</a>
                        </li>
                    @endcan
                    @can('permission', 'refunds:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('refunds.index') }}" @if (request()->routeIs('refunds.*')) aria-current="page" @endif>Refunds</a>
                        </li>
                    @endcan
                    @can('permission', 'parties:manage')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('parties.index') }}" @if (request()->routeIs('parties.*')) aria-current="page" @endif>Customers &amp; suppliers</a>
                        </li>
                    @endcan
                    @can('permission', 'commercial:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('quotations.index') }}" @if (request()->routeIs('quotations.*')) aria-current="page" @endif>Quotations</a>
                        </li>
                    @endcan
                    @can('permission', 'accounting:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('accounting.index') }}" @if (request()->routeIs('accounting.*')) aria-current="page" @endif>Accounting</a>
                        </li>
                    @endcan
                    @can('permission', 'expenses:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('operations.index') }}" @if (request()->routeIs('operations.*')) aria-current="page" @endif>Operations</a>
                        </li>
                    @endcan
                    @can('permission', 'administration:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('administration.index') }}" @if (request()->routeIs('administration.*')) aria-current="page" @endif>Administration</a>
                        </li>
                    @endcan
                    @can('permission', 'documents:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('documents.index') }}" @if (request()->routeIs('documents.*')) aria-current="page" @endif>Documents</a>
                        </li>
                    @endcan
                    @can('permission', 'reports:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('reports.index') }}" @if (request()->routeIs('reports.*')) aria-current="page" @endif>Reports &amp; analytics</a>
                        </li>
                    @endcan
                    @can('permission', 'platform:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('platform.index') }}" @if (request()->routeIs('platform.*')) aria-current="page" @endif>Platform</a>
                        </li>
                    @endcan
                    @can('permission', 'workflows:read')
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('workflows.index') }}" @if (request()->routeIs('workflows.*')) aria-current="page" @endif>Workflows</a>
                        </li>
                    @endcan
                </ul>
                <div class="sidebar-user">
                    <div class="small text-white-50">Signed in as</div>
                    <div class="fw-semibold text-truncate">{{ auth()->user()->name }}</div>
                    <span class="badge bg-secondary">{{ auth()->user()->role }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm w-100">Log out</button>
                    </form>
                </div>
            </div>
        </div>
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

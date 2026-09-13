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
                @php
                    // Sidebar restructured into the 9 top-level groups the
                    // NamRA e-VAT MS master prompt names (sections 16-21),
                    // matching the source's own db/runtime.ts navigation
                    // seed 1:1 in taxonomy, order and permission gating.
                    // Documents & Records / Reporting & Analytics /
                    // Administration / Licensing & Subscription / Platform
                    // are kept unchanged and resorted after the 9, per the
                    // master prompt's own "keep everything not expressly
                    // modified" rule. Each group's routeIs() patterns decide
                    // which group starts expanded.
                    $groups = [
                        'dashboard' => ['dashboard', 'portals.*'],
                        'vat-management' => ['vat-management.*', 'vat-periods.*', 'vat-returns.*', 'refunds.*', 'risk-indicators.*', 'audit-cases.*', 'disputes.*', 'obligations.*', 'compliance-overview.*'],
                        'invoice-management' => ['invoice-management.*', 'invoices.*'],
                        'accounting-finance' => ['accounting.*'],
                        'operations' => ['operations.*'],
                        'quotation' => ['quotation.*', 'quotations.*'],
                        'project-management' => ['project-management.*'],
                        'registered' => ['registered.*', 'business-parties.*'],
                        'new-registration' => ['new-registration.*'],
                        'documents' => ['documents.*'],
                        'reporting' => ['reports.*'],
                        'administration' => ['administration.*', 'organisations.*', 'workflows.*'],
                        'licensing' => ['licensing.*'],
                        'platform' => ['platform.*'],
                    ];
                    $activeGroup = collect($groups)->search(fn ($patterns) => request()->routeIs(...$patterns)) ?: 'dashboard';
                @endphp
                <ul class="nav flex-column sidebar-nav flex-grow-1">
                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-dashboard" aria-expanded="{{ $activeGroup === 'dashboard' ? 'true' : 'false' }}">
                            <span>Dashboard</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'dashboard') show @endif" id="group-dashboard">
                            <li><a class="nav-link" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a></li>
                            <li><a class="nav-link" href="{{ route('portals.index') }}" @if (request()->routeIs('portals.*')) aria-current="page" @endif>Portals</a></li>
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-vat-management" aria-expanded="{{ $activeGroup === 'vat-management' ? 'true' : 'false' }}">
                            <span>VAT Management</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'vat-management') show @endif" id="group-vat-management">
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('vat-management.audit-report') }}" @if (request()->routeIs('vat-management.audit-report')) aria-current="page" @endif>VAT Audit Report</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('vat-management.reconciliation') }}" @if (request()->routeIs('vat-management.reconciliation')) aria-current="page" @endif>Invoice Reconciliation</a></li>
                            @endcan
                            @can('permission', 'returns:read')
                                <li><a class="nav-link" href="{{ route('vat-periods.index') }}" @if (request()->routeIs('vat-periods.*', 'vat-returns.*')) aria-current="page" @endif>VAT Returns</a></li>
                            @endcan
                            @can('permission', 'refunds:read')
                                <li><a class="nav-link" href="{{ route('refunds.index') }}" @if (request()->routeIs('refunds.*')) aria-current="page" @endif>VAT Refund Report</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('vat-management.adjustment-report') }}" @if (request()->routeIs('vat-management.adjustment-report')) aria-current="page" @endif>VAT Adjustment Report</a></li>
                            @endcan
                            @can('permission', 'risk:read')
                                <li><a class="nav-link" href="{{ route('risk-indicators.index') }}" @if (request()->routeIs('risk-indicators.*')) aria-current="page" @endif>Risk Indicators</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('audit-cases.index') }}" @if (request()->routeIs('audit-cases.*')) aria-current="page" @endif>Audit Cases &amp; Risk</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('disputes.index') }}" @if (request()->routeIs('disputes.*')) aria-current="page" @endif>Disputes</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('obligations.index') }}" @if (request()->routeIs('obligations.*')) aria-current="page" @endif>Obligations</a></li>
                            @endcan
                            @can('permission', 'compliance:read')
                                <li><a class="nav-link" href="{{ route('compliance-overview.index') }}" @if (request()->routeIs('compliance-overview.*')) aria-current="page" @endif>Compliance Overview</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-invoice-management" aria-expanded="{{ $activeGroup === 'invoice-management' ? 'true' : 'false' }}">
                            <span>Invoice Management</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'invoice-management') show @endif" id="group-invoice-management">
                            @can('permission', 'invoices:read')
                                <li><a class="nav-link" href="{{ route('invoice-management.local') }}" @if (request()->routeIs('invoice-management.local')) aria-current="page" @endif>Local Invoices</a></li>
                                <li><a class="nav-link" href="{{ route('invoice-management.foreign') }}" @if (request()->routeIs('invoice-management.foreign')) aria-current="page" @endif>Foreign Invoices</a></li>
                                <li><a class="nav-link" href="{{ route('invoices.index') }}" @if (request()->routeIs('invoices.*')) aria-current="page" @endif>All Invoices</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-accounting-finance" aria-expanded="{{ $activeGroup === 'accounting-finance' ? 'true' : 'false' }}">
                            <span>Accounting &amp; Finance</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'accounting-finance') show @endif" id="group-accounting-finance">
                            @can('permission', 'accounting:read')
                                <li><a class="nav-link" href="{{ route('accounting.index') }}" @if (request()->routeIs('accounting.index')) aria-current="page" @endif>General Ledger</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.supplier-ledger') }}" @if (request()->routeIs('accounting.supplier-ledger')) aria-current="page" @endif>Supplier Ledger</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.customer-ledger') }}" @if (request()->routeIs('accounting.customer-ledger')) aria-current="page" @endif>Customer Ledger</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.fixed-assets') }}" @if (request()->routeIs('accounting.fixed-assets')) aria-current="page" @endif>Fixed Asset Module</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.budgets') }}" @if (request()->routeIs('accounting.budgets')) aria-current="page" @endif>Budgets</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.purchase-orders') }}" @if (request()->routeIs('accounting.purchase-orders')) aria-current="page" @endif>Purchase Orders</a></li>
                                <li><a class="nav-link" href="{{ route('accounting.cash-flow') }}" @if (request()->routeIs('accounting.cash-flow')) aria-current="page" @endif>Cash Flow Projects</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-operations" aria-expanded="{{ $activeGroup === 'operations' ? 'true' : 'false' }}">
                            <span>Operations</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'operations') show @endif" id="group-operations">
                            @can('permission', 'expenses:read')
                                <li><a class="nav-link" href="{{ route('operations.index') }}" @if (request()->routeIs('operations.index')) aria-current="page" @endif>Expenses, Inventory &amp; Project Register</a></li>
                                <li><a class="nav-link" href="{{ route('operations.human-resources') }}" @if (request()->routeIs('operations.human-resources')) aria-current="page" @endif>Human Resources Module</a></li>
                                <li><a class="nav-link" href="{{ route('operations.immovable-assets') }}" @if (request()->routeIs('operations.immovable-assets')) aria-current="page" @endif>Immovable Asset Management</a></li>
                                <li><a class="nav-link" href="{{ route('operations.movable-assets') }}" @if (request()->routeIs('operations.movable-assets')) aria-current="page" @endif>Movable Asset Management</a></li>
                                <li><a class="nav-link" href="{{ route('operations.logistics') }}" @if (request()->routeIs('operations.logistics')) aria-current="page" @endif>Logistics Module</a></li>
                                <li><a class="nav-link" href="{{ route('operations.erp') }}" @if (request()->routeIs('operations.erp')) aria-current="page" @endif>ERP Module</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-quotation" aria-expanded="{{ $activeGroup === 'quotation' ? 'true' : 'false' }}">
                            <span>Quotation</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'quotation') show @endif" id="group-quotation">
                            @can('permission', 'commercial:read')
                                <li><a class="nav-link" href="{{ route('quotations.index') }}" @if (request()->routeIs('quotations.*')) aria-current="page" @endif>Create New Quotation</a></li>
                                <li><a class="nav-link" href="{{ route('quotations.index') }}">Issued Quotations</a></li>
                                <li><a class="nav-link" href="{{ route('quotation.converted') }}" @if (request()->routeIs('quotation.converted')) aria-current="page" @endif>Converted Quotations</a></li>
                                <li><a class="nav-link" href="{{ route('quotation.converted-invoices') }}" @if (request()->routeIs('quotation.converted-invoices')) aria-current="page" @endif>Converted Quotations into Invoices</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-project-management" aria-expanded="{{ $activeGroup === 'project-management' ? 'true' : 'false' }}">
                            <span>Project Management</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'project-management') show @endif" id="group-project-management">
                            @can('permission', 'projects:read')
                                <li><a class="nav-link" href="{{ route('project-management.new') }}" @if (request()->routeIs('project-management.new')) aria-current="page" @endif>Create New Project</a></li>
                                <li><a class="nav-link" href="{{ route('project-management.ongoing') }}" @if (request()->routeIs('project-management.ongoing')) aria-current="page" @endif>Ongoing Project Reports</a></li>
                                <li><a class="nav-link" href="{{ route('project-management.completed') }}" @if (request()->routeIs('project-management.completed')) aria-current="page" @endif>Completed Projects</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-registered" aria-expanded="{{ $activeGroup === 'registered' ? 'true' : 'false' }}">
                            <span>Registered</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'registered') show @endif" id="group-registered">
                            @can('permission', 'parties:manage')
                                <li><a class="nav-link" href="{{ route('business-parties.index', ['relationship' => 'CUSTOMER']) }}" @if (request()->routeIs('business-parties.*') && request()->query('relationship') === 'CUSTOMER') aria-current="page" @endif>Customers</a></li>
                                <li><a class="nav-link" href="{{ route('business-parties.index', ['relationship' => 'SUPPLIER']) }}" @if (request()->routeIs('business-parties.*') && request()->query('relationship') === 'SUPPLIER') aria-current="page" @endif>Suppliers</a></li>
                                <li><a class="nav-link" href="{{ route('registered.service-providers') }}" @if (request()->routeIs('registered.service-providers')) aria-current="page" @endif>Service Providers</a></li>
                            @endcan
                        </ul>
                    </li>

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-new-registration" aria-expanded="{{ $activeGroup === 'new-registration' ? 'true' : 'false' }}">
                            <span>New Registration</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'new-registration') show @endif" id="group-new-registration">
                            @can('permission', 'parties:manage')
                                <li><a class="nav-link" href="{{ route('business-parties.index', ['relationship' => 'CUSTOMER']) }}">New Customer</a></li>
                                <li><a class="nav-link" href="{{ route('business-parties.index', ['relationship' => 'SUPPLIER']) }}">New Supplier</a></li>
                            @endcan
                            @can('permission', 'commercial:read')
                                <li><a class="nav-link" href="{{ route('quotations.index') }}">New Quote</a></li>
                            @endcan
                            @can('permission', 'invoices:read')
                                <li><a class="nav-link" href="{{ route('new-registration.credit-note') }}" @if (request()->routeIs('new-registration.credit-note')) aria-current="page" @endif>New Credit Note</a></li>
                                <li><a class="nav-link" href="{{ route('new-registration.debit-note') }}" @if (request()->routeIs('new-registration.debit-note')) aria-current="page" @endif>New Debit Note</a></li>
                            @endcan
                        </ul>
                    </li>

                    @can('permission', 'documents:read')
                        <li class="nav-item"><a class="nav-link" href="{{ route('documents.index') }}" @if (request()->routeIs('documents.*')) aria-current="page" @endif>Documents &amp; Records</a></li>
                    @endcan
                    @can('permission', 'reports:read')
                        <li class="nav-item"><a class="nav-link" href="{{ route('reports.index') }}" @if (request()->routeIs('reports.*')) aria-current="page" @endif>Reporting &amp; Analytics</a></li>
                    @endcan

                    <li class="nav-item sidebar-group">
                        <button class="nav-link sidebar-group-trigger" type="button" data-bs-toggle="collapse" data-bs-target="#group-administration" aria-expanded="{{ $activeGroup === 'administration' ? 'true' : 'false' }}">
                            <span>Administration</span><span class="sidebar-group-caret" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="collapse sidebar-subnav @if ($activeGroup === 'administration') show @endif" id="group-administration">
                            @can('permission', 'administration:read')
                                <li><a class="nav-link" href="{{ route('administration.index') }}" @if (request()->routeIs('administration.*')) aria-current="page" @endif>Administration Command Centre</a></li>
                            @endcan
                            @can('permission', 'identity:read')
                                <li><a class="nav-link" href="{{ route('organisations.index') }}" @if (request()->routeIs('organisations.*')) aria-current="page" @endif>Organisations</a></li>
                            @endcan
                            @can('permission', 'workflows:read')
                                <li><a class="nav-link" href="{{ route('workflows.index') }}" @if (request()->routeIs('workflows.*')) aria-current="page" @endif>Workflows</a></li>
                            @endcan
                        </ul>
                    </li>

                    @can('permission', 'licensing:read')
                        <li class="nav-item"><a class="nav-link" href="{{ route('licensing.index') }}" @if (request()->routeIs('licensing.*')) aria-current="page" @endif>Licensing &amp; Subscription</a></li>
                    @endcan
                    @can('permission', 'platform:read')
                        <li class="nav-item"><a class="nav-link" href="{{ route('platform.index') }}" @if (request()->routeIs('platform.*')) aria-current="page" @endif>Platform</a></li>
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

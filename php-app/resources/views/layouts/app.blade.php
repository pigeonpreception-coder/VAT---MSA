<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') &middot; @endif{{ config('app.name', 'VAT-MSA') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="{{ auth()->check() ? 'app-authenticated' : 'app-guest' }}">
    {{-- WCAG 2.1 SC 2.4.1 "Bypass Blocks": visually hidden until focused. --}}
    <a class="visually-hidden-focusable skip-link" href="#main-content">Skip to main content</a>

    @auth
        @php
            $currentUser = auth()->user();
            $currentRoleLabel = \App\Support\Access\RoleLabels::for($currentUser->role);
            $currentContext = $currentUser->organisation()?->legal_name ?? $tenantAuthorityName;
            $currentInitials = collect(preg_split('/\s+/', trim($currentUser->name)))
                ->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');

            // Breadcrumb: group > section > page. On a section's own index
            // page the section label is the last crumb; on a detail page
            // the section becomes a link and the page title follows it.
            $pageTitle = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES) ?: config('app.name', 'VAT-MSA');
            $crumbs = [];
            if ($navigation['activeGroup']) {
                $crumbs[] = ['label' => $navigation['activeGroup']['label'], 'url' => null];
            }
            $activeItem = $navigation['activeItem'];
            if ($activeItem && request()->routeIs($activeItem['route'])) {
                $currentCrumb = $activeItem['label'];
            } else {
                if ($activeItem) {
                    $crumbs[] = ['label' => $activeItem['label'], 'url' => $activeItem['url']];
                }
                $currentCrumb = $pageTitle;
            }
            if ($crumbs !== [] && $crumbs[0]['label'] === $currentCrumb) {
                array_shift($crumbs);
            }
        @endphp

        <div class="app-shell">
            {{-- Below the lg breakpoint this becomes an off-canvas drawer
                 (Bootstrap's responsive .offcanvas-lg). Deliberately not the
                 plain .offcanvas class as well: its unconditional drawer
                 transform fights offcanvas-lg's always-visible desktop mode. --}}
            <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main navigation">
                <div class="sidebar-header">
                    <a class="sidebar-brand" href="{{ route('dashboard') }}">
                        <span class="sidebar-brand-mark" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                        <span class="sidebar-brand-text">
                            <span class="sidebar-brand-name">VAT-MSA</span>
                            <span class="sidebar-brand-tagline">VAT management system</span>
                        </span>
                    </a>
                    <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close navigation"></button>
                </div>

                <nav class="sidebar-nav" aria-label="Primary">
                    <ul class="list-unstyled mb-0" id="sidebar-accordion">
                        @foreach ($navigation['groups'] as $group)
                            <li class="sidebar-group @if ($group['locked']) is-locked @endif">
                                <button class="sidebar-group-toggle @unless ($group['current']) collapsed @endunless" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#nav-{{ $group['key'] }}"
                                    aria-expanded="{{ $group['current'] ? 'true' : 'false' }}" aria-controls="nav-{{ $group['key'] }}">
                                    <i class="bi bi-{{ $group['icon'] }} sidebar-group-icon" aria-hidden="true"></i>
                                    <span>{{ $group['label'] }}</span>
                                    @if ($group['locked'])
                                        <i class="bi bi-lock-fill sidebar-lock" aria-hidden="true" title="You don't have access to this area"></i>
                                    @endif
                                    <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                                </button>
                                <ul class="collapse list-unstyled sidebar-subnav @if ($group['current']) show @endif" id="nav-{{ $group['key'] }}" data-bs-parent="#sidebar-accordion">
                                    @foreach ($group['items'] as $item)
                                        <li>
                                            <a class="sidebar-link @if ($item['locked']) is-locked @endif" href="{{ $item['url'] }}"
                                                @if ($item['current']) aria-current="page" @endif
                                                @if ($item['locked']) title="You don't have access to this page" @endif>
                                                <span>{{ $item['label'] }}</span>
                                                @if ($item['locked'])
                                                    <i class="bi bi-lock-fill sidebar-lock" aria-hidden="true"></i>
                                                    <span class="visually-hidden">(no access)</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>

            <div class="app-main">
                <header class="app-topbar">
                    <button class="topbar-menu-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open navigation">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>

                    <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
                        <ol class="breadcrumb mb-0">
                            @foreach ($crumbs as $crumb)
                                <li class="breadcrumb-item">
                                    @if ($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else{{ $crumb['label'] }}@endif
                                </li>
                            @endforeach
                            <li class="breadcrumb-item active" aria-current="page">{{ $currentCrumb }}</li>
                        </ol>
                    </nav>

                    <div class="topbar-actions">
                        @if ($navigation['searchUrl'])
                            <form class="topbar-search d-none d-md-block" role="search" method="GET" action="{{ $navigation['searchUrl'] }}">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" name="q" class="form-control" placeholder="Search workspace" aria-label="Search workspace"
                                    minlength="2" maxlength="80" value="{{ request()->routeIs('workspace-search.*') ? request('q') : '' }}">
                            </form>
                        @endif

                        <div class="dropdown">
                            <button class="topbar-user" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for {{ $currentUser->name }}">
                                <span class="avatar" aria-hidden="true">{{ $currentInitials }}</span>
                                <span class="topbar-user-text d-none d-md-flex">
                                    <span class="topbar-user-name">{{ $currentUser->name }}</span>
                                    <span class="topbar-user-role">{{ $currentRoleLabel }}</span>
                                </span>
                                <i class="bi bi-chevron-down topbar-user-caret d-none d-md-inline" aria-hidden="true"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end account-menu">
                                <div class="account-menu-header">
                                    <div class="fw-semibold text-truncate">{{ $currentUser->name }}</div>
                                    <div class="small text-muted text-truncate">{{ $currentUser->email }}</div>
                                    <div class="account-menu-context">
                                        <span class="badge text-bg-light">{{ $currentRoleLabel }}</span>
                                        @if ($currentContext)
                                            <span class="small text-muted text-truncate">{{ $currentContext }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="dropdown-divider"></div>
                                @foreach ($navigation['accountLinks'] as $link)
                                    <a class="dropdown-item @if ($link['locked']) is-locked @endif" href="{{ $link['url'] }}"
                                        @if ($link['current']) aria-current="page" @endif
                                        @if ($link['locked']) title="You don't have access to this page" @endif>
                                        <i class="bi bi-{{ $link['icon'] }}" aria-hidden="true"></i> {{ $link['label'] }}
                                        @if ($link['locked'])<i class="bi bi-lock-fill ms-auto" aria-hidden="true"></i>@endif
                                    </a>
                                @endforeach
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Log out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main id="main-content" class="app-content" tabindex="-1">
                    @include('layouts.partials.flash')
                    @yield('content')
                </main>
            </div>
        </div>
    @else
        <main id="main-content" class="app-guest-content" tabindex="-1">
            @include('layouts.partials.flash')
            @yield('content')
        </main>
    @endauth

    @stack('scripts')
</body>
</html>

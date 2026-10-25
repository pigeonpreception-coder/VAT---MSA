@php
    $nav = [
        ['route' => 'site.product', 'label' => 'Product'],
        ['route' => 'site.trust', 'label' => 'Security'],
        ['route' => 'site.plans', 'label' => 'Plans'],
        ['route' => 'site.verify', 'label' => 'Verify an invoice'],
    ];
@endphp
<header class="site-header" data-header>
    <div class="wrap site-header__inner">
        <a class="brand" href="{{ route('site.home') }}" aria-label="VAT-MSA home">
            <x-site.logo />
        </a>

        <nav class="nav" aria-label="Primary">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" @if (request()->routeIs($item['route'])) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="header-cta">
            @auth
                <a class="btn btn--sm" href="{{ route('dashboard') }}">Open dashboard <x-site.icon name="arrow-right" :size="16" /></a>
            @else
                <a class="link-quiet" href="{{ route('login') }}">Sign in</a>
                <a class="btn btn--sm" href="{{ route('signup.create') }}">Request access</a>
            @endauth
        </div>

        <button class="menu-btn" type="button" aria-expanded="false" aria-controls="site-menu" data-menu-toggle>
            <span class="sr-only">Menu</span>
            <x-site.icon name="menu" class="menu-btn__open" />
            <x-site.icon name="x" class="menu-btn__close" />
        </button>
    </div>

    <div class="mobile-menu" id="site-menu" hidden>
        <nav class="wrap mobile-menu__inner" aria-label="Mobile">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" @if (request()->routeIs($item['route'])) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
            <div class="mobile-menu__cta">
                @auth
                    <a class="btn btn--block" href="{{ route('dashboard') }}">Open dashboard</a>
                @else
                    <a class="btn btn--block" href="{{ route('signup.create') }}">Request access</a>
                    <a class="btn btn--ghost btn--block" href="{{ route('login') }}">Sign in</a>
                @endauth
            </div>
        </nav>
    </div>
</header>

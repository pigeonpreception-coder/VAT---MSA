@php
    $pageTitle = trim($__env->yieldContent('title')) ?: 'VAT-MSA — e-VAT management for Namibia';
    $pageDescription = trim($__env->yieldContent('description')) ?: 'VAT-MSA validates and certifies every invoice, reconciles supplier and customer records, and keeps a tamper-evident audit trail. An e-VAT management platform for Namibia, now in pilot.';
    $canonical = url()->current();
    $ogImage = url('/site/og.jpg');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="theme-color" content="#0B1B33">
    <meta name="color-scheme" content="light">
    <link rel="canonical" href="{{ $canonical }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="VAT-MSA">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='11' fill='%230B1B33'/%3E%3Cpath d='M10.5 13.5 18.6 27.2 30 11' stroke='%23fff' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round' fill='none'/%3E%3Ccircle cx='28.6' cy='28.4' r='3.1' fill='%23E3A72F'/%3E%3C/svg%3E">
    {{-- Safari and older browsers ignore SVG favicons and fall back to these. --}}
    <link rel="icon" href="/favicon.ico" sizes="48x48 32x32 16x16">
    <link rel="apple-touch-icon" href="/site/apple-touch-icon.png">

    <link rel="preload" href="/site/fonts/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/site/fonts/newsreader-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>

    {{-- Marks the page as script-enabled before first paint, so the scroll-reveal
         styles never hide content for a visitor whose JavaScript is off or blocked. --}}
    <script>document.documentElement.classList.add('js')</script>

    @include('site.partials.styles')

    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'VAT-MSA', 'url' => url('/'), 'description' => $pageDescription], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @stack('head')
</head>
<body class="@yield('body_class')">
    <a class="skip" href="#main">Skip to main content</a>

    @include('site.partials.header')

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    @include('site.partials.footer')
    @include('site.partials.scripts')
    @stack('scripts')
</body>
</html>

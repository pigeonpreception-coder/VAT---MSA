@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Back'])

{{-- Standard page heading: optional back link (detail pages), title, one-line
     description, and an `actions` slot for the page's primary buttons. --}}
<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div class="page-header-text">
        @if ($back)
            <a href="{{ $back }}" class="page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ $backLabel }}</a>
        @endif
        <h1 class="page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>

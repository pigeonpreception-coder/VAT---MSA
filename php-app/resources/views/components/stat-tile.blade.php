@props(['label', 'value', 'hint' => null, 'tone' => null, 'icon' => null, 'href' => null])

{{-- One KPI tile. `tone` (success|warning|danger) colours the hint line. --}}
@php
    $tag = $href ? 'a' : 'div';
    $toneClass = match ($tone) {
        'success' => 'text-success',
        'warning' => 'text-warning-emphasis',
        'danger' => 'text-danger',
        default => 'text-muted',
    };
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'stat-tile card h-100'.($href ? ' stat-tile-link' : '')]) }}>
    <div class="card-body">
        <div class="stat-label">
            @if ($icon)<i class="bi bi-{{ $icon }}" aria-hidden="true"></i>@endif
            <span>{{ $label }}</span>
            @if ($href)<i class="bi bi-arrow-right stat-arrow" aria-hidden="true"></i>@endif
        </div>
        <div class="stat-value">{{ $value }}</div>
        @if ($hint)
            <div class="stat-hint {{ $toneClass }}">{{ $hint }}</div>
        @endif
        {{ $slot }}
    </div>
</{{ $tag }}>

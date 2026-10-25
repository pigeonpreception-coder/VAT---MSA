@props(['wordmark' => true, 'size' => 36, 'inverse' => false])
{{-- VAT-MSA mark: a rounded seal holding a "V" whose right stroke runs on into
     a check, with a gold sun dot -- certification, and Namibia's sun. The
     gradient id is per-instance so several logos on one page never collide. --}}
@php $gid = 'lg'.substr(md5((string) microtime(true).random_int(0, 99999)), 0, 6); @endphp
<span {{ $attributes->merge(['class' => 'logo'.($inverse ? ' logo--inverse' : '')]) }}>
    <svg class="logo__mark" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 40 40" fill="none" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="{{ $gid }}" x1="4" y1="2" x2="36" y2="38" gradientUnits="userSpaceOnUse">
                <stop stop-color="#1D3A6B"/>
                <stop offset="1" stop-color="#0B1B33"/>
            </linearGradient>
        </defs>
        <rect width="40" height="40" rx="11" fill="url(#{{ $gid }})"/>
        <rect x=".5" y=".5" width="39" height="39" rx="10.5" stroke="#fff" stroke-opacity=".14"/>
        <path d="M10.5 13.5 18.6 27.2 30 11" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="28.6" cy="28.4" r="3.1" fill="#E3A72F"/>
    </svg>
    @if ($wordmark)
        <span class="logo__word">VAT<span class="logo__dash">-</span>MSA</span>
    @endif
</span>

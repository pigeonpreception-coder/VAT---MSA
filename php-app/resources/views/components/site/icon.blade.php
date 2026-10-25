@props(['name', 'size' => 24])
@php
    // One consistent set: 24px grid, 1.6 stroke, round caps and joins. Every
    // value is a developer-authored literal, which is what makes the
    // unescaped {!! !!} output below safe.
    $icons = [
        'shield-check' => '<path d="M12 3 4.5 6v5.6c0 4.3 3.1 7.9 7.5 9.4 4.4-1.5 7.5-5.1 7.5-9.4V6L12 3Z"/><path d="m8.7 12.2 2.3 2.3 4.3-4.7"/>',
        'file-check' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/><path d="m9 14.6 2 2 4-4.4"/>',
        'file-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'qr' => '<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.2"/><rect x="14" y="3.5" width="6.5" height="6.5" rx="1.2"/><rect x="3.5" y="14" width="6.5" height="6.5" rx="1.2"/><path d="M14 14h2.6v2.6M20.5 14v.01M14 20.5h.01M17.5 18.5v2h3"/>',
        'scale' => '<path d="M12 4v16M8 20h8M5 7h14"/><path d="m5 7-3 7a3.4 3.4 0 0 0 6 0L5 7ZM19 7l-3 7a3.4 3.4 0 0 0 6 0l-3-7Z"/>',
        'chart' => '<path d="M4 20V11M10 20V4M16 20v-6M22 20H2"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5M12 14.6v2.4"/>',
        'key' => '<circle cx="8" cy="15.5" r="4"/><path d="m11 12.5 8.5-8.5M16 7.5l3 3M13.5 10l2 2"/>',
        'users' => '<circle cx="9" cy="8.2" r="3.3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.3a3.1 3.1 0 0 1 0 5.8M18.2 14.4c2 .9 3.3 2.7 3.3 5.1"/>',
        'code' => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13.6 5 10.4 19"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3A4 4 0 1 0 13 5.3l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 1 0 11 18.7l1-1"/>',
        'plug' => '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0V8ZM12 17v4"/>',
        'radar' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.4"/><path d="m12 12 5.6-5.6"/><circle cx="12" cy="12" r=".9" fill="currentColor"/>',
        'gavel' => '<path d="m13.5 5.5 5 5M8.5 10.5l5 5M11.5 2.5l7 7-3.5 3.5-7-7 3.5-3.5Z"/><path d="m9 13-6 6a1.5 1.5 0 0 0 2.1 2.1l6-6M13.5 20.5H21"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.4-4M4 4.5v3.2h3.2"/><path d="M4 13a8 8 0 0 0 14.4 4M20 19.5v-3.2h-3.2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9.5 8h5M9.5 12h5"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12.5 9 5 9-5M3 17l9 5 9-5"/>',
        'building' => '<rect x="5" y="3" width="14" height="18" rx="1.6"/><path d="M9 7.5h1.5M13.5 7.5H15M9 11.5h1.5M13.5 11.5H15M10 21v-4h4v4"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.4 2.7 2.7 5.3-5.5"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-up-right' => '<path d="M7 17 17 7M8 7h9v9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10.5h18M16 14.8h2"/>',
        'truck' => '<path d="M3 6.5h11v9.5H3zM14 9.5h4l3 3V16h-7"/><circle cx="7.5" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2.2"/><path d="m7 9.5 3 2.5-3 2.5M13 15h4"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="1.8"/><rect x="3" y="13" width="18" height="7" rx="1.8"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'sun' => '<circle cx="12" cy="12" r="3.8"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/>',
        'wifi-off' => '<path d="M3 9.2A14 14 0 0 1 7 6.8M21 9.2a14 14 0 0 0-8-3.9"/><path d="M6 12.7a9 9 0 0 1 2.5-1.8M18 12.7a9 9 0 0 0-3.6-2.1"/><path d="M12 17h.01M3 3l18 18"/>',
        'alert' => '<path d="M12 4 2.8 19.5h18.4L12 4Z"/><path d="M12 10v4.4M12 17.2v.01"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'fingerprint' => '<path d="M6.5 18.5c-.8-2-1-4-.6-6 .6-3.4 3.3-5.8 6.6-5.8 2.6 0 4.8 1.4 5.8 3.6"/><path d="M9.5 20.5c-.9-2-1.2-4.3-.8-6.6.3-1.7 1.6-3 3.3-3 1.9 0 3.3 1.5 3.3 3.3 0 2.4.5 4.4 1.6 6"/><path d="M12 14.2c0 2.4.5 4.6 1.6 6.3"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $icons[$name] ?? '' !!}</svg>

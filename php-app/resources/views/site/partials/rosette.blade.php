{{-- Decorative guilloche rosette: rings of rotated ellipses, the construction
     security printing uses on banknotes and certificates. Generated here with
     loops rather than hand-written paths; ids are unique per include so a page
     can carry it more than once. --}}
@php $r = 'ro'.substr(md5(uniqid('', true)), 0, 6); @endphp
<svg viewBox="0 0 400 400" fill="none" stroke="currentColor" stroke-width=".55" aria-hidden="true" focusable="false">
    <defs>
        <ellipse id="{{ $r }}a" cx="200" cy="200" rx="192" ry="62"/>
        <ellipse id="{{ $r }}b" cx="200" cy="200" rx="158" ry="104"/>
        <ellipse id="{{ $r }}c" cx="200" cy="200" rx="122" ry="36"/>
    </defs>
    <g opacity=".95">@for ($i = 0; $i < 60; $i++)<use href="#{{ $r }}a" transform="rotate({{ $i * 6 }} 200 200)"/>@endfor</g>
    <g opacity=".7">@for ($i = 0; $i < 45; $i++)<use href="#{{ $r }}b" transform="rotate({{ $i * 8 }} 200 200)"/>@endfor</g>
    <g opacity=".6">@for ($i = 0; $i < 36; $i++)<use href="#{{ $r }}c" transform="rotate({{ $i * 10 }} 200 200)"/>@endfor</g>
    <circle cx="200" cy="200" r="20"/>
    <circle cx="200" cy="200" r="28"/>
    <circle cx="200" cy="200" r="198" opacity=".5"/>
</svg>

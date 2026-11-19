@props(['title', 'subtitle' => null])

{{-- Frame for the signed-out pages: brand, a single centred card, and the
     form's validation errors summarised at the top. --}}
<div class="auth-shell">
    <div class="auth-brand">
        <span class="sidebar-brand-mark" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
        <span>
            <span class="auth-brand-name d-block">VAT-MSA</span>
            <span class="auth-brand-tagline d-block">VAT management system</span>
        </span>
    </div>
    <div class="card auth-card">
        <div class="card-body">
            <h1 class="auth-title">{{ $title }}</h1>
            @if ($subtitle)
                <p class="text-muted small mb-4">{{ $subtitle }}</p>
            @endif

            <x-form-errors />

            {{ $slot }}
        </div>
    </div>
    @isset($footer)
        <div class="small text-muted mt-3">{{ $footer }}</div>
    @endisset
</div>

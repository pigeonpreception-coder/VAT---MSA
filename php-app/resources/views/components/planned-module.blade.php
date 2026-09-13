@props(['eyebrow', 'title', 'description', 'scopeNote'])

<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">{{ $eyebrow }}</div>
    <h1 class="h3 mb-1">{{ $title }}</h1>
    <p class="text-muted mb-0">{{ $description }}</p>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h6 mb-0">Reserved for a future release</h2>
            <div class="small text-muted">Architecture and navigation placeholder</div>
        </div>
        <x-status-badge value="DRAFT" />
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-0" role="status">
            <strong>This module is not built yet.</strong><br>
            {{ $scopeNote }}
        </div>
    </div>
</div>

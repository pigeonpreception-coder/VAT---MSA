@extends('layouts.app')

@section('title', 'Super administration portal')

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $disabledIntegrations = collect($snapshot['integrations'])->where('operational_status', 'DISABLED')->count();
    $pendingEvents = (int) (collect($snapshot['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0);
    $criticalSecurity = (int) (collect($snapshot['securityEvents'])->firstWhere('severity', 'CRITICAL')['count'] ?? 0);
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Super administration</div>
    <h1 class="h3 mb-1">Technical health, security and integration configuration</h1>
    <p class="text-muted mb-0">This projection uses a technical read model. It excludes invoices, return values, documents, refunds, taxpayer identifiers and internal tax-risk records by default.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Components</span><span>C</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['components'])) }}</div>
            <div class="small text-muted">Capability-specific readiness</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Disabled integrations</span><span>I</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($disabledIntegrations) }}</div>
            <div class="small {{ $disabledIntegrations > 0 ? 'text-warning' : 'text-success' }}">Contracts or credentials required</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Pending events</span><span>E</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($pendingEvents) }}</div>
            <div class="small text-muted">Durable outbox backlog</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Critical security events</span><span>S</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($criticalSecurity) }}</div>
            <div class="small {{ $criticalSecurity > 0 ? 'text-danger' : 'text-success' }}">Technical signal count</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Service component posture</div>
        <div class="text-muted small">A dependency block cannot be hidden by overall availability</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Service components, their criticality, configuration and operational status</caption>
            <thead>
                <tr><th scope="col">Component</th><th scope="col">Type</th><th scope="col">Criticality</th><th scope="col">Configuration</th><th scope="col">Operations</th><th scope="col">Dependency</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['components'] as $item)
                    <tr>
                        <td><strong>{{ $item['display_name'] }}</strong></td>
                        <td>{{ $titleCase($item['component_type']) }}</td>
                        <td><x-status-badge :value="$item['criticality']" type="risk" /></td>
                        <td><x-status-badge :value="$item['configuration_status']" type="status" /></td>
                        <td><x-status-badge :value="$item['operational_status']" type="status" /></td>
                        <td>{{ $item['dependency_summary'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No service components on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

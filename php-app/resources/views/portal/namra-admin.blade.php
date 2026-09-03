@extends('layouts.app')

@section('title', 'NamRA administration portal')

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $federationReady = collect($governance['federation'])->where('status', 'PRODUCTION_APPROVED')->count();
    $productionActivated = collect($governance['onboardingCases'])->where('status', 'PRODUCTION_ACTIVATED')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Tax Authority governance</div>
    <h1 class="h3 mb-1">Authority provisioning, federation and activation control</h1>
    <p class="text-muted mb-0">Authority hierarchy, protected roles, federation posture, independent onboarding decisions and quarterly access review. Live federation and production activation remain disabled until approved evidence exists.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Assigned authorities</span><span>A</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($governance['authorities'])) }}</div>
            <div class="small text-muted">Explicit administration scope</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Authority units</span><span>H</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($governance['units'])) }}</div>
            <div class="small text-muted">Governed hierarchy</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Federation ready</span><span>F</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($federationReady) }}</div>
            <div class="small text-warning">Contract and conformance required</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Production activated</span><span>P</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($productionActivated) }}</div>
            <div class="small text-warning">Disabled in this environment</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Authority hierarchy</div>
                <div class="text-muted small">Jurisdiction-scoped organisational units</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Authority units, their type and status</caption>
                    <thead><tr><th scope="col">Unit</th><th scope="col">Type</th><th scope="col">Parent</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['units'] as $item)
                            <tr>
                                <td><strong>{{ $item['name'] }}</strong><div class="text-muted small font-monospace">{{ $item['code'] }}</div></td>
                                <td>{{ $titleCase($item['unit_type']) }}</td>
                                <td class="font-monospace small">{{ $item['parent_unit_id'] ?? '—' }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No authority units on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Federation registrations</div>
                <div class="text-muted small">Protocol is unconfirmed until the authority contract is approved</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Identity federation connections, their environment, protocol and status</caption>
                    <thead><tr><th scope="col">Provider</th><th scope="col">Environment</th><th scope="col">Protocol</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['federation'] as $item)
                            <tr>
                                <td><strong>{{ $item['display_name'] }}</strong><div class="text-muted small font-monospace">{{ $item['provider_key'] }}</div></td>
                                <td>{{ $titleCase($item['environment']) }}</td>
                                <td>{{ $item['protocol'] }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No federation registrations on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Protected administrative assignments</div>
        <div class="text-muted small">Maker and activation duties cannot be combined; all assignments retain approval evidence</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Administrators with a protected governance role assignment</caption>
            <thead><tr><th scope="col">Administrator</th><th scope="col">Role</th><th scope="col">Duty</th><th scope="col">Scope</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($governance['assignments'] as $item)
                    <tr>
                        <td><strong>{{ $item['display_name'] }}</strong><div class="text-muted small">{{ $item['email'] }}</div></td>
                        <td>{{ $item['role_name'] }}</td>
                        <td>{{ $titleCase($item['duty_class']) }}</td>
                        <td class="font-monospace small">{{ $item['scope'] }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No protected assignments on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Quarterly authority access review</div>
                <div class="text-muted small">Privileged decisions fail closed without a current review</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Quarterly access reviews, their period, due date and status</caption>
                    <thead><tr><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['accessReviews'] as $item)
                            <tr>
                                <td>{{ $date($item['period_start']) }}</td>
                                <td>{{ $dateTime($item['due_at']) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-4">No access reviews on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Identity provider boundary</div>
                <div class="text-muted small">Authentication never grants authority by itself</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Identity providers, their status and configuration</caption>
                    <thead><tr><th scope="col">Provider</th><th scope="col">Status</th><th scope="col">Configuration</th></tr></thead>
                    <tbody>
                        @forelse ($identity['providers'] as $item)
                            <tr>
                                <td><strong>{{ $item['display_name'] }}</strong><div class="text-muted small font-monospace">{{ $item['provider_key'] }}</div></td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                                <td><x-status-badge :value="$item['configuration_status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-4">No identity providers on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3" role="status">
    <strong>No implicit authority or financial access.</strong><br>
    A local-staging approval proves only the internal governance workflow. It does not activate ITAS federation, a production Tax Authority, statutory rules, taxpayer accounts, tax subscriptions or transaction access.
</div>
@endsection

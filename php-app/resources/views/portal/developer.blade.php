@extends('layouts.app')

@section('title', 'Developer portal')

@php
    $activeCredentials = collect($snapshot['clients'])->where('status', 'ACTIVE')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Developer and sandbox</div>
    <h1 class="h3 mb-1">Applications, contracts, webhooks and conformance posture</h1>
    <p class="text-muted mb-0">Production tax data is not a developer-portal concern. Machine credentials remain external, client scopes are explicit, webhook subscriptions are signed, and production approval remains disabled until conformance evidence exists.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Applications</span><span>A</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['clients'])) }}</div>
            <div class="small text-muted">Tenant-scoped client registrations</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active credentials</span><span>K</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($activeCredentials) }}</div>
            <div class="small text-muted">Secret values never displayed</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Webhooks</span><span>W</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['webhooks'])) }}</div>
            <div class="small text-muted">Signed endpoint contracts</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Conformance</span><span>C</span></div>
            <div class="fs-2 fw-semibold">Pending</div>
            <div class="small text-warning">Sandbox certification not configured</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Application registry</div>
        <div class="text-muted small">Scopes, lifecycle and rate profile remain inspectable</div>
    </div>
    @if (count($snapshot['clients']))
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <caption class="visually-hidden">Registered API client applications, their scopes and status</caption>
                <thead>
                    <tr><th scope="col">Application</th><th scope="col">Client key</th><th scope="col">Scopes</th><th scope="col">Rate profile</th><th scope="col">Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($snapshot['clients'] as $item)
                        <tr>
                            <td><strong>{{ $item['name'] }}</strong></td>
                            <td class="font-monospace">{{ $item['client_key'] }}</td>
                            <td class="font-monospace">{{ $item['scopes'] }}</td>
                            <td>{{ $item['rate_limit_profile'] }}</td>
                            <td><x-status-badge :value="$item['status']" type="status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card-body text-center text-muted py-4">
            <strong class="d-block text-body">No applications in scope</strong>
            An authorised organisation administrator must create the client registration before credentials can be provisioned.
        </div>
    @endif
</div>
@endsection

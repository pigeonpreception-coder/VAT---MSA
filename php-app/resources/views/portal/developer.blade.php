@extends('layouts.app')

@section('title', 'Developer portal')

@php
    $activeCredentials = collect($snapshot['clients'])->where('status', 'ACTIVE')->count();
    $ranClients = collect($snapshot['clients'])->whereNotNull('conformance_outcome');
    $passedClients = $ranClients->where('conformance_outcome', 'PASSED')->count();
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
            @if ($ranClients->isEmpty())
                <div class="fs-2 fw-semibold">Pending</div>
                <div class="small text-warning">No conformance run has been recorded yet</div>
            @else
                <div class="fs-2 fw-semibold">{{ $passedClients }}/{{ $ranClients->count() }}</div>
                <div class="small {{ $passedClients === $ranClients->count() ? 'text-success' : 'text-warning' }}">Applications passing their latest conformance run</div>
            @endif
        </div></div>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($canManage)
    <div class="card mb-4">
        <div class="card-header">
            <div class="fw-semibold">Register application</div>
            <div class="text-muted small">Issues a client_key immediately; the credential itself stays pending provisioning</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('portal.developer.create') }}" class="row g-2 align-items-end">
                @csrf
                <x-idempotency-key />
                <div class="col-md-3">
                    <label for="name" class="form-label small mb-0">Application name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4">
                    <label for="scopes" class="form-label small mb-0">Scopes (comma-separated)</label>
                    <input type="text" id="scopes" name="scopes" value="{{ old('scopes') }}" placeholder="invoices.read, quotations.read" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label for="rate_limit_profile" class="form-label small mb-0">Rate limit profile</label>
                    <select id="rate_limit_profile" name="rate_limit_profile" class="form-select form-select-sm" required>
                        <option value="SANDBOX">SANDBOX</option>
                        <option value="PILOT_STANDARD">PILOT_STANDARD</option>
                        <option value="PILOT_ELEVATED">PILOT_ELEVATED</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Register</button>
                </div>
            </form>
        </div>
    </div>
@endif

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
                    <tr>
                        <th scope="col">Application</th><th scope="col">Client key</th><th scope="col">Scopes</th><th scope="col">Rate profile</th><th scope="col">Status</th><th scope="col">Conformance</th>
                        @if ($canManage)
                            <th scope="col">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($snapshot['clients'] as $item)
                        <tr>
                            <td><strong>{{ $item['name'] }}</strong></td>
                            <td class="font-monospace">{{ $item['client_key'] }}</td>
                            <td class="font-monospace">{{ $item['scopes'] }}</td>
                            <td>{{ $item['rate_limit_profile'] }}</td>
                            <td><x-status-badge :value="$item['status']" type="status" /></td>
                            <td>
                                @if ($item['conformance_outcome'])
                                    <x-status-badge :value="$item['conformance_outcome']" type="status" />
                                @else
                                    <span class="text-muted small">Not run</span>
                                @endif
                            </td>
                            @if ($canManage)
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <form method="POST" action="{{ route('portal.developer.rotate', $item['id']) }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100" {{ $item['status'] === 'REVOKED' ? 'disabled' : '' }}>Rotate credential</button>
                                        </form>
                                        <form method="POST" action="{{ route('portal.developer.conformance', $item['id']) }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">Run conformance</button>
                                        </form>
                                        @if ($item['status'] !== 'REVOKED')
                                            <form method="POST" action="{{ route('portal.developer.revoke', $item['id']) }}" onsubmit="return confirm('Revoke this application\'s credential? This cannot be undone.');">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="text" name="reason" placeholder="Revocation reason (10-500 chars)" class="form-control form-control-sm mb-1" required minlength="10" maxlength="500">
                                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">Revoke credential</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
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

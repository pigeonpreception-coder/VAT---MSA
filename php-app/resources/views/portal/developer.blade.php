@extends('layouts.app')

@section('title', 'Developer portal')

@php
    $activeCredentials = collect($snapshot['clients'])->where('status', 'ACTIVE')->count();
    $ranClients = collect($snapshot['clients'])->whereNotNull('conformance_outcome');
    $passedClients = $ranClients->where('conformance_outcome', 'PASSED')->count();
@endphp

@section('content')
<x-page-header title="Developer portal" :back="route('portals.index')" back-label="Portals"
    subtitle="Register applications, manage their API credentials and track conformance testing. Credential secrets are never displayed." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Applications" icon="app-indicator" :value="number_format(count($snapshot['clients']))" hint="Registered for your organisation" />
    </div>
    <div class="col">
        <x-stat-tile label="Active credentials" icon="key" :value="number_format($activeCredentials)" hint="Applications able to authenticate" />
    </div>
    <div class="col">
        <x-stat-tile label="Webhooks" icon="broadcast" :value="number_format(count($snapshot['webhooks']))" hint="Signed event subscriptions" />
    </div>
    <div class="col">
        @if ($ranClients->isEmpty())
            <x-stat-tile label="Conformance" icon="patch-check" value="Not run" hint="No conformance run recorded yet" tone="warning" />
        @else
            <x-stat-tile label="Conformance" icon="patch-check" :value="$passedClients.'/'.$ranClients->count()" hint="Passing their latest conformance run"
                :tone="$passedClients === $ranClients->count() ? 'success' : 'warning'" />
        @endif
    </div>
</div>

<x-form-errors />

@if ($canManage)
    <div class="card mb-4">
        <div class="card-header">
            <div class="fw-semibold">Register an application</div>
            <div class="text-muted small">A client key is issued straight away; the credential itself is provisioned separately</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('portal.developer.create') }}" class="row g-3 align-items-end">
                @csrf
                <x-idempotency-key />
                <div class="col-md-3">
                    <label for="name" class="form-label">Application name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="scopes" class="form-label">Scopes</label>
                    <input type="text" id="scopes" name="scopes" value="{{ old('scopes') }}" placeholder="invoices.read, quotations.read" class="form-control" required aria-describedby="scopes-help">
                    <div id="scopes-help" class="form-text">Separate scopes with commas.</div>
                </div>
                <div class="col-md-3">
                    <label for="rate_limit_profile" class="form-label">Rate limit</label>
                    <select id="rate_limit_profile" name="rate_limit_profile" class="form-select" required>
                        <option value="SANDBOX">Sandbox</option>
                        <option value="PILOT_STANDARD">Pilot standard</option>
                        <option value="PILOT_ELEVATED">Pilot elevated</option>
                    </select>
                </div>
                <div class="col-md-2 align-self-start pt-md-4">
                    <button type="submit" class="btn btn-primary w-100 mt-md-1"><i class="bi bi-plus-lg" aria-hidden="true"></i> Register</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Applications</div>
        <div class="text-muted small">Scopes, status, rate limit and latest conformance result</div>
    </div>
    @if (count($snapshot['clients']))
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <caption class="visually-hidden">Registered API client applications, their scopes and status</caption>
                <thead>
                    <tr>
                        <th scope="col">Application</th><th scope="col">Client key</th><th scope="col">Scopes</th><th scope="col">Rate limit</th><th scope="col">Status</th><th scope="col">Conformance</th>
                        @if ($canManage)
                            <th scope="col" class="text-end">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($snapshot['clients'] as $item)
                        <tr>
                            <td class="fw-semibold">{{ $item['name'] }}</td>
                            <td class="font-monospace">{{ $item['client_key'] }}</td>
                            <td class="font-monospace small">{{ $item['scopes'] }}</td>
                            <td>@humanize($item['rate_limit_profile'])</td>
                            <td><x-status-badge :value="$item['status']" type="status" /></td>
                            <td>
                                @if ($item['conformance_outcome'])
                                    <x-status-badge :value="$item['conformance_outcome']" type="status" />
                                @else
                                    <span class="text-muted small">Not run</span>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="text-end actions-cell">
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <form method="POST" action="{{ route('portal.developer.rotate', $item['id']) }}">
                                                @csrf
                                                <x-idempotency-key />
                                                <button type="submit" class="btn btn-outline-secondary btn-sm" {{ $item['status'] === 'REVOKED' ? 'disabled' : '' }}><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Rotate credential</button>
                                            </form>
                                            <form method="POST" action="{{ route('portal.developer.conformance', $item['id']) }}">
                                                @csrf
                                                <x-idempotency-key />
                                                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-play" aria-hidden="true"></i> Run conformance</button>
                                            </form>
                                        </div>
                                        @if ($item['status'] !== 'REVOKED')
                                            <form method="POST" action="{{ route('portal.developer.revoke', $item['id']) }}" class="d-flex gap-1" onsubmit="return confirm('Revoke this application\'s credential? This cannot be undone.');">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="text" name="reason" placeholder="Reason for revoking (10–500 characters)" aria-label="Reason for revoking" class="form-control form-control-sm" required minlength="10" maxlength="500">
                                                <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Revoke credential</button>
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
        <div class="empty-state">
            <i class="bi bi-app-indicator" aria-hidden="true"></i>
            <strong>No applications registered</strong>
            An organisation administrator needs to register an application before credentials can be provisioned.
        </div>
    @endif
</div>
@endsection

@extends('layouts.app')

@section('title', 'API clients & webhooks')

@php
    $activeClients = collect($data['clients'])->where('status', 'ACTIVE')->count();
    $pendingEvents = collect($data['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0;
@endphp

@section('content')
<x-page-header title="API clients & webhooks"
    subtitle="API clients and webhook subscriptions across the platform. Client secrets are kept in a secure vault and are never shown." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">API clients</div>
            <div class="stat-value">{{ number_format(count($data['clients'])) }}</div>
            <div class="small text-muted">System accounts with limited access</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Active clients</div>
            <div class="stat-value">{{ number_format($activeClients) }}</div>
            <div class="small text-muted">Credentials are issued outside this page</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Webhooks</div>
            <div class="stat-value">{{ number_format(count($data['webhooks'])) }}</div>
            <div class="small text-muted">Signed event notifications</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Outbox pending</div>
            <div class="stat-value">{{ number_format($pendingEvents) }}</div>
            <div class="small text-warning">Waiting for a delivery service</div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">API client registry</div>
        <div class="text-muted small">Secrets are never shown here</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">API clients, their scopes, credential reference and status</caption>
            <thead>
                <tr>
                    <th scope="col">Client</th>
                    <th scope="col">Organisation</th>
                    <th scope="col">Scopes</th>
                    <th scope="col">Credential reference</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last rotated</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['clients'] as $client)
                    <tr>
                        <td>
                            <strong>{{ $client['name'] }}</strong>
                            <div class="text-muted small font-monospace">{{ $client['client_key'] }}</div>
                        </td>
                        <td>{{ $client['legal_name'] ?? $client['organisation_id'] }}</td>
                        <td class="font-monospace">{{ $client['scopes'] }}</td>
                        <td><x-status-badge :value="$client['credential_reference'] ? 'EXTERNAL_REFERENCE' : 'MISSING'" type="status" /></td>
                        <td><x-status-badge :value="$client['status']" type="status" /></td>
                        <td>{{ $client['last_rotated_at'] ? \Illuminate\Support\Carbon::parse($client['last_rotated_at'])->format('d M Y, H:i') : 'Never' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4"><strong>No API clients.</strong> Provisioning requires developer management permission and an external secret manager.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Webhook subscriptions</div>
        <div class="text-muted small">Every webhook uses HTTPS, a signing key, and automatic retries</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Webhook subscriptions, their events, signing key and status</caption>
            <thead>
                <tr>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Events</th>
                    <th scope="col">Signing key</th>
                    <th scope="col">Status</th>
                    <th scope="col">Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['webhooks'] as $webhook)
                    <tr>
                        <td class="font-monospace">{{ $webhook['endpoint_url'] }}</td>
                        <td class="font-monospace">{{ $webhook['event_types'] }}</td>
                        <td><x-status-badge :value="$webhook['signing_key_reference'] ? 'EXTERNAL_REFERENCE' : 'MISSING'" type="status" /></td>
                        <td><x-status-badge :value="$webhook['status']" type="status" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($webhook['created_at'])->format('d M Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4"><strong>No webhook subscriptions.</strong> No external endpoint will receive events until its contract and signing key are configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Events are queued safely but not delivered yet: no delivery service is connected in this environment. Nothing is marked as delivered until it really is.
</p>
@endsection

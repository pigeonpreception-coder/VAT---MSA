@extends('layouts.app')

@section('title', 'Developer and webhooks')

@php
    $activeClients = collect($data['clients'])->where('status', 'ACTIVE')->count();
    $pendingEvents = collect($data['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0;
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Controlled interoperability</div>
    <h1 class="h3 mb-1">API clients, webhook contracts and durable delivery</h1>
    <p class="text-muted mb-0">Machine access is deny-by-default. Client credentials remain in an external secret manager, webhook delivery is signed, and domain events first commit to a transactional outbox.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">API clients</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($data['clients'])) }}</div>
            <div class="small text-muted">Scoped machine identities</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Active clients</div>
            <div class="fs-2 fw-semibold">{{ number_format($activeClients) }}</div>
            <div class="small text-muted">Credentials must be externally provisioned</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Webhooks</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($data['webhooks'])) }}</div>
            <div class="small text-muted">Signed delivery subscriptions</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Outbox pending</div>
            <div class="fs-2 fw-semibold">{{ number_format($pendingEvents) }}</div>
            <div class="small text-warning">Consumer connection pending</div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">API client registry</div>
        <div class="text-muted small">Secret values never appear in this registry</div>
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
        <div class="text-muted small">HTTPS, signing-key references, retries and dead-letter handling are mandatory</div>
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

<div class="alert alert-info">
    <strong>Publishing remains safely queued.</strong><br>
    The outbox is durable, but no external event-bus or webhook worker is configured. Events are preserved instead of being marked delivered without evidence.
</div>
@endsection

@extends('layouts.app')

@section('title', 'Integrations and platform health')

@php
    $configured = collect($data['integrations'])->where('configuration_status', 'CONFIGURED')->count();
    // Matches the source's own filter exactly: syncJobs is either a full
    // row set (one row per job, technical-admin actors excepted) or a
    // status+count aggregate (technical-admin actors, see
    // IntegrationsViewController's own doc comment) -- either way this
    // counts matching *rows*, not a summed count, faithfully reproducing
    // the source's own coarser aggregate-actor behaviour rather than
    // "fixing" it into something the source itself never computes.
    $blockedJobs = collect($data['syncJobs'])->filter(fn ($job) => str_contains((string) $job['status'], 'BLOCKED'))->count();
    $outboxPending = collect($data['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0;
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Platform ecosystem</div>
    <h1 class="h3 mb-1">Integration contracts and operational health</h1>
    <p class="text-muted mb-0">Each external capability has an explicit contract, credential reference, configuration state and health outcome. Missing authority or banking agreements remain disabled rather than simulated.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Connections</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($data['integrations'])) }}</div>
            <div class="small text-muted">Government, banking and payment boundaries</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Configured</div>
            <div class="fs-2 fw-semibold">{{ number_format($configured) }}</div>
            <div class="small text-muted">Ready for governed execution</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Blocked jobs</div>
            <div class="fs-2 fw-semibold">{{ number_format($blockedJobs) }}</div>
            <div class="small text-warning">External setup required</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Outbox pending</div>
            <div class="fs-2 fw-semibold">{{ number_format($outboxPending) }}</div>
            <div class="small text-muted">Durable events awaiting publisher</div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Integration registry</div>
        <div class="text-muted small">No credentials are stored in application tables</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Integration connections, their configuration and operational status</caption>
            <thead>
                <tr>
                    <th scope="col">Provider</th>
                    <th scope="col">Category</th>
                    <th scope="col">Capabilities</th>
                    <th scope="col">Configuration</th>
                    <th scope="col">Operations</th>
                    <th scope="col">Classification</th>
                    <th scope="col">Health</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['integrations'] as $integration)
                    <tr>
                        <td>
                            <strong>{{ $integration['display_name'] }}</strong>
                            <div class="text-muted small font-monospace">{{ $integration['provider_key'] }}</div>
                        </td>
                        <td>{{ $integration['category'] }}</td>
                        <td class="font-monospace">{{ $integration['capabilities'] }}</td>
                        <td><x-status-badge :value="$integration['configuration_status']" type="status" /></td>
                        <td><x-status-badge :value="$integration['operational_status']" type="status" /></td>
                        <td>{{ $integration['data_classification'] }}</td>
                        <td>{{ $integration['last_health_check_at'] ? \Illuminate\Support\Carbon::parse($integration['last_health_check_at'])->format('d M Y, H:i') : 'Not checked' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No integration connections are registered.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Service component posture</div>
        <div class="text-muted small">Readiness is capability-specific, not one global green light</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Service components, their criticality and operational status</caption>
            <thead>
                <tr>
                    <th scope="col">Component</th>
                    <th scope="col">Type</th>
                    <th scope="col">Criticality</th>
                    <th scope="col">Configuration</th>
                    <th scope="col">Operational status</th>
                    <th scope="col">Dependency</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                {{-- Named $serviceComponent, not $component -- Blade's own
                     compiled <x-status-badge> tags internally bind a
                     variable literally named $component (the
                     AnonymousComponent instance), so a loop variable
                     sharing that name shadows it and breaks every
                     component tag inside the loop body. --}}
                @forelse ($data['components'] as $serviceComponent)
                    <tr>
                        <td><strong>{{ $serviceComponent['display_name'] }}</strong></td>
                        <td>{{ $serviceComponent['component_type'] }}</td>
                        <td><x-status-badge :value="$serviceComponent['criticality']" type="risk" /></td>
                        <td><x-status-badge :value="$serviceComponent['configuration_status']" type="status" /></td>
                        <td><x-status-badge :value="$serviceComponent['operational_status']" type="status" /></td>
                        <td>{{ $serviceComponent['dependency_summary'] }}</td>
                        <td>{{ $serviceComponent['status_detail'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No service components are registered.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

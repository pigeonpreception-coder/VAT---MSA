@extends('layouts.app')

@section('title', 'Compliance Overview')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain</div>
    <h1 class="h3 mb-1">Compliance Overview</h1>
    <p class="text-muted mb-0">A single read-only aggregate across every compliance signal -- the same snapshot the JSON API's own compliance endpoint returns.</p>
</div>

@php
    // Every stat card links out to that domain's own dedicated page rather
    // than duplicating a full table here -- see this controller's own doc
    // comment. Route::has() guards each link because this build-out ships
    // several of these domains as separate, independently-mergeable PRs
    // off main (see docs/MIGRATION_MATRIX.md): whichever of Obligations/
    // Disputes merges after this page does would otherwise 500 on a route
    // name that doesn't exist yet on main at merge time. A card degrades to
    // plain (unlinked) text until its own PR lands, then activates with no
    // further change needed here.
    $stats = [
        ['key' => 'obligations', 'label' => 'Obligations', 'route' => 'obligations.index'],
        ['key' => 'cases', 'label' => 'Audit Cases', 'route' => 'audit-cases.index'],
        ['key' => 'disputes', 'label' => 'Disputes', 'route' => 'disputes.index'],
        ['key' => 'risks', 'label' => 'Risk Indicators', 'route' => 'risk-indicators.index'],
        ['key' => 'refunds', 'label' => 'Refunds', 'route' => 'refunds.index'],
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach ($stats as $stat)
        <div class="col-6 col-md-4 col-lg-2">
            @if (Route::has($stat['route']))
                <a href="{{ route($stat['route']) }}" class="card text-decoration-none h-100">
                    <div class="card-body text-center">
                        <div class="display-6">{{ $counts[$stat['key']] }}</div>
                        <div class="text-muted small">{{ $stat['label'] }}</div>
                    </div>
                </a>
            @else
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="display-6">{{ $counts[$stat['key']] }}</div>
                        <div class="text-muted small">{{ $stat['label'] }}</div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Communications</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Recent communications</caption>
                    <thead>
                        <tr>
                            <th scope="col">Subject</th>
                            <th scope="col">Channel</th>
                            <th scope="col">Direction</th>
                            <th scope="col">Status</th>
                            <th scope="col">Occurred</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (array_slice($snapshot['communications'], 0, 10) as $communication)
                            <tr>
                                <td>{{ $communication['subject'] }}<div class="text-muted small">{{ $userNames[$communication['actor_id']] ?? '' }}</div></td>
                                <td>{{ ucfirst(strtolower($communication['channel'])) }}</td>
                                <td>{{ ucfirst(strtolower($communication['direction'])) }}</td>
                                <td><x-status-badge :value="$communication['status']" type="status" /></td>
                                <td>{{ \Illuminate\Support\Carbon::parse($communication['occurred_at'])->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No communications recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Notifications</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Recent notifications</caption>
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (array_slice($snapshot['notifications'], 0, 10) as $notification)
                            <tr>
                                <td>{{ $notification['title'] }}<div class="text-muted small">{{ $notification['message'] }}</div></td>
                                <td><x-status-badge :value="$notification['severity']" type="risk" /></td>
                                <td><x-status-badge :value="$notification['status']" type="status" /></td>
                                <td>{{ \Illuminate\Support\Carbon::parse($notification['created_at'])->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No notifications recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Consent grants</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Consent grants</caption>
                    <thead>
                        <tr>
                            <th scope="col">Purpose</th>
                            <th scope="col">Grantee</th>
                            <th scope="col">Status</th>
                            <th scope="col">Valid until</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['consents'] as $consent)
                            <tr>
                                <td>{{ $consent['purpose'] }}<div class="text-muted small">Granted by {{ $userNames[$consent['granted_by']] ?? 'Unknown' }}</div></td>
                                <td>{{ ucfirst(strtolower($consent['grantee_type'])) }}: {{ $consent['grantee_id'] }}</td>
                                <td><x-status-badge :value="$consent['status']" type="status" /></td>
                                <td>{{ $consent['valid_to'] ? \Illuminate\Support\Carbon::parse($consent['valid_to'])->format('d M Y') : 'Ongoing' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No consent grants recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Delegations</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Delegations</caption>
                    <thead>
                        <tr>
                            <th scope="col">Delegator</th>
                            <th scope="col">Delegate</th>
                            <th scope="col">Scopes</th>
                            <th scope="col">Status</th>
                            <th scope="col">Valid until</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['delegations'] as $delegation)
                            <tr>
                                <td>{{ $userNames[$delegation['delegator_user_id']] ?? 'Unknown' }}</td>
                                <td>{{ $userNames[$delegation['delegate_user_id']] ?? 'Unknown' }}</td>
                                <td>{{ implode(', ', json_decode($delegation['scopes'], true) ?? []) }}</td>
                                <td><x-status-badge :value="$delegation['status']" type="status" /></td>
                                <td>{{ $delegation['valid_to'] ? \Illuminate\Support\Carbon::parse($delegation['valid_to'])->format('d M Y') : 'Ongoing' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No delegations recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

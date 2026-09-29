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
    $previewLimit = 5;
@endphp
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-4">
    @foreach ($stats as $stat)
        <div class="col">
            @if (Route::has($stat['route']))
                <a href="{{ route($stat['route']) }}" class="card h-100 text-decoration-none text-reset">
                    <div class="card-body">
                        <div class="d-flex justify-content-between text-muted small text-uppercase"><span>{{ $stat['label'] }}</span><span>&rarr;</span></div>
                        <div class="fs-2 fw-semibold">{{ number_format($counts[$stat['key']]) }}</div>
                        <div class="small text-muted">View all</div>
                    </div>
                </a>
            @else
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between text-muted small text-uppercase"><span>{{ $stat['label'] }}</span></div>
                        <div class="fs-2 fw-semibold">{{ number_format($counts[$stat['key']]) }}</div>
                        <div class="small text-muted">&nbsp;</div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>

<div class="row row-cols-1 row-cols-lg-2 g-3">
    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Communications</span>
                <span class="badge text-bg-light border">{{ count($snapshot['communications']) }}</span>
            </div>
            <div class="card-body">
                @forelse ($snapshot['communications'] as $index => $communication)
                    <div class="mb-3 pb-3 border-bottom comm-item" @if ($index >= $previewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $communication['subject'] }}</strong>
                            <x-status-badge :value="$communication['status']" type="status" />
                        </div>
                        <div class="text-muted small mt-1">{{ ucfirst(strtolower($communication['channel'])) }} &middot; {{ ucfirst(strtolower($communication['direction'])) }}</div>
                        <div class="text-muted small mt-1">{{ $userNames[$communication['actor_id']] ?? 'Unknown' }} &middot; {{ \Illuminate\Support\Carbon::parse($communication['occurred_at'])->format('d M Y') }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No communications recorded.</p>
                @endforelse
                @if (count($snapshot['communications']) > $previewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="comm-item" data-show-more-count="{{ count($snapshot['communications']) - $previewLimit }}">
                        Show {{ count($snapshot['communications']) - $previewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Notifications</span>
                <span class="badge text-bg-light border">{{ count($snapshot['notifications']) }}</span>
            </div>
            <div class="card-body">
                @forelse ($snapshot['notifications'] as $index => $notification)
                    <div class="mb-3 pb-3 border-bottom notif-item" @if ($index >= $previewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $notification['title'] }}</strong>
                            <x-status-badge :value="$notification['severity']" type="risk" />
                        </div>
                        <div class="small mt-1">{{ \Illuminate\Support\Str::limit($notification['message'], 90) }}</div>
                        <div class="text-muted small mt-1"><x-status-badge :value="$notification['status']" type="status" /> &middot; {{ \Illuminate\Support\Carbon::parse($notification['created_at'])->format('d M Y') }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No notifications recorded.</p>
                @endforelse
                @if (count($snapshot['notifications']) > $previewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="notif-item" data-show-more-count="{{ count($snapshot['notifications']) - $previewLimit }}">
                        Show {{ count($snapshot['notifications']) - $previewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Consent grants</span>
                <span class="badge text-bg-light border">{{ count($snapshot['consents']) }}</span>
            </div>
            <div class="card-body">
                @forelse ($snapshot['consents'] as $index => $consent)
                    <div class="mb-3 pb-3 border-bottom consent-item" @if ($index >= $previewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $consent['purpose'] }}</strong>
                            <x-status-badge :value="$consent['status']" type="status" />
                        </div>
                        <div class="text-muted small mt-1">Granted by {{ $userNames[$consent['granted_by']] ?? 'Unknown' }}</div>
                        <div class="text-muted small mt-1">{{ ucfirst(strtolower($consent['grantee_type'])) }}: {{ $consent['grantee_id'] }} &middot; {{ $consent['valid_to'] ? \Illuminate\Support\Carbon::parse($consent['valid_to'])->format('d M Y') : 'Ongoing' }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No consent grants recorded.</p>
                @endforelse
                @if (count($snapshot['consents']) > $previewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="consent-item" data-show-more-count="{{ count($snapshot['consents']) - $previewLimit }}">
                        Show {{ count($snapshot['consents']) - $previewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Delegations</span>
                <span class="badge text-bg-light border">{{ count($snapshot['delegations']) }}</span>
            </div>
            <div class="card-body">
                @forelse ($snapshot['delegations'] as $index => $delegation)
                    <div class="mb-3 pb-3 border-bottom delegation-item" @if ($index >= $previewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $userNames[$delegation['delegator_user_id']] ?? 'Unknown' }} &rarr; {{ $userNames[$delegation['delegate_user_id']] ?? 'Unknown' }}</strong>
                            <x-status-badge :value="$delegation['status']" type="status" />
                        </div>
                        <div class="text-muted small mt-1">Scopes: {{ implode(', ', json_decode($delegation['scopes'], true) ?? []) }}</div>
                        <div class="text-muted small mt-1">{{ $delegation['valid_to'] ? \Illuminate\Support\Carbon::parse($delegation['valid_to'])->format('d M Y') : 'Ongoing' }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No delegations recorded.</p>
                @endforelse
                @if (count($snapshot['delegations']) > $previewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="delegation-item" data-show-more-count="{{ count($snapshot['delegations']) - $previewLimit }}">
                        Show {{ count($snapshot['delegations']) - $previewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-show-more-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.dataset.expanded === '1';
                var items = document.querySelectorAll('.' + button.dataset.showMoreTarget);
                items.forEach(function (item, index) {
                    if (index >= 5) { item.hidden = expanded; }
                });
                button.dataset.expanded = expanded ? '0' : '1';
                button.textContent = expanded
                    ? 'Show ' + button.dataset.showMoreCount + ' more'
                    : 'Show fewer';
            });
        });
    })();
</script>
@endpush

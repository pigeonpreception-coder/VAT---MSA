@extends('layouts.app')

@section('title', 'Compliance overview')

@section('content')
<x-page-header title="Compliance overview"
    subtitle="Every compliance area in one place, with a link to each area's full page." />

@php
    // Each tile links to that area's own page rather than repeating its
    // table here (see ComplianceOverviewViewController's doc comment).
    $stats = [
        ['key' => 'obligations', 'label' => 'Obligations', 'route' => 'obligations.index', 'icon' => 'calendar-check'],
        ['key' => 'cases', 'label' => 'Audit cases', 'route' => 'audit-cases.index', 'icon' => 'briefcase'],
        ['key' => 'disputes', 'label' => 'Disputes', 'route' => 'disputes.index', 'icon' => 'exclamation-diamond'],
        ['key' => 'risks', 'label' => 'Risk indicators', 'route' => 'risk-indicators.index', 'icon' => 'activity'],
        ['key' => 'refunds', 'label' => 'VAT refunds', 'route' => 'refunds.index', 'icon' => 'cash-coin'],
        // RT-007: Communications has its own page, so it gets a count + link too.
        ['key' => 'communications', 'label' => 'Communications', 'route' => 'communications.index', 'icon' => 'envelope'],
    ];
    $previewLimit = 5;
@endphp
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-6 g-3 mb-4">
    @foreach ($stats as $stat)
        <div class="col">
            <x-stat-tile :label="$stat['label']" :icon="$stat['icon']" :value="number_format($counts[$stat['key']])"
                :href="Route::has($stat['route']) ? route($stat['route']) : null" />
        </div>
    @endforeach
</div>

<div class="row row-cols-1 row-cols-lg-3 g-3">
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
                        <div class="text-muted small mt-1">@humanize($consent['grantee_type']): {{ $consent['grantee_id'] }} &middot; {{ $consent['valid_to'] ? \Illuminate\Support\Carbon::parse($consent['valid_to'])->format('d M Y') : 'Ongoing' }}</div>
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

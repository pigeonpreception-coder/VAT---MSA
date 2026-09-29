@extends('layouts.app')

@section('title', 'Audit trail')

@php
    $sidePreviewLimit = 5;
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance &amp; security</div>
    <h1 class="h3 mb-1">Audit trail and hash-chain verification</h1>
    <p class="text-muted mb-0">Every audit event is chained by hash to the one before it. Search the trail, or run an on-demand verification pass that re-derives each event's hash and confirms nothing has been tampered with or lost.</p>
</div>

@if (session('chainBreak'))
    <div class="alert alert-danger" role="alert">{{ session('chainBreak') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Total events</span><span>N</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['total']) }}</div>
                <div class="small text-muted">System-wide</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Matching filters</span><span>&equiv;</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['matching']) }}</div>
                <div class="small text-muted">Current search</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Chain status</span><span>&#128274;</span></div>
                <div class="fs-2 fw-semibold">{{ $metrics['verification_status'] ? ucfirst(strtolower($metrics['verification_status'])) : 'Not run' }}</div>
                <div class="small {{ $metrics['verification_status'] === 'FAILED' ? 'text-danger' : 'text-muted' }}">Most recent verification</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Verification runs</span><span>#</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['verification_runs']) }}</div>
                <div class="small text-muted">All-time</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Chain verification</div>
                    <div class="text-muted small">On-demand -- this deployment has no scheduled job infrastructure to run it automatically.</div>
                </div>
                <form method="POST" action="{{ route('audit-trail.verify') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Run chain verification</button>
                </form>
            </div>
            @if (count($verifications))
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <caption class="visually-hidden">Recent audit chain verification runs</caption>
                        <thead>
                            <tr><th scope="col">Started</th><th scope="col">Status</th><th scope="col">Verified count</th><th scope="col">First break</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($verifications as $verification)
                                <tr>
                                    <td>{{ $verification->started_at->format('Y-m-d H:i:s') }}</td>
                                    <td><x-status-badge :value="$verification->status" type="status" /></td>
                                    <td>{{ number_format($verification->verified_count) }}</td>
                                    <td class="small">
                                        @if ($verification->first_break_id)
                                            {{ $verification->first_break_reason }} <span class="text-muted">({{ $verification->first_break_id }})</span>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="card-body text-center text-muted py-3">No verification has been run yet.</div>
            @endif
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('audit-trail.index') }}" class="row g-2">
                    <div class="col-md-3">
                        <label for="resource_type" class="form-label small mb-0">Resource type</label>
                        <input type="text" id="resource_type" name="resource_type" value="{{ $filters['resource_type'] ?? '' }}" class="form-control form-control-sm" placeholder="e.g. INVOICE">
                    </div>
                    <div class="col-md-3">
                        <label for="resource_id" class="form-label small mb-0">Resource ID</label>
                        <input type="text" id="resource_id" name="resource_id" value="{{ $filters['resource_id'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label for="action" class="form-label small mb-0">Action</label>
                        <input type="text" id="action" name="action" value="{{ $filters['action'] ?? '' }}" class="form-control form-control-sm" placeholder="e.g. INVOICE_CERTIFIED">
                    </div>
                    <div class="col-md-3">
                        <label for="actor_id" class="form-label small mb-0">Actor ID</label>
                        <input type="text" id="actor_id" name="actor_id" value="{{ $filters['actor_id'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="text-muted small">{{ number_format($totalCount) }} event{{ $totalCount === 1 ? '' : 's' }} match{{ $totalCount === 1 ? 'es' : '' }} these filters</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Audit event trail</caption>
                    <thead>
                        <tr><th scope="col">Occurred</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Resource</th><th scope="col">Outcome</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td class="small">{{ $event->occurred_at->format('Y-m-d H:i:s') }}</td>
                                <td class="small">{{ $event->actor_role }} <span class="text-muted">({{ $event->actor_id }})</span></td>
                                <td class="small font-monospace">{{ $event->action }}</td>
                                <td class="small">{{ $event->resource_type }} <span class="text-muted">{{ $event->resource_id }}</span></td>
                                <td><x-status-badge :value="$event->outcome" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No audit events match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">Events by resource type</div>
            <div class="card-body">
                @forelse ($byResourceType as $row)
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <span>{{ ucwords(strtolower(str_replace('_', ' ', $row->resource_type))) }}</span>
                        <span class="fw-semibold">{{ number_format($row->count) }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No audit events recorded yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Most active actors</span>
                <span class="badge text-bg-light border">{{ count($byActor) }}</span>
            </div>
            <div class="card-body">
                @forelse ($byActor as $index => $row)
                    <div class="mb-3 pb-3 border-bottom most-active-actor-item" @if ($index >= $sidePreviewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $row->actor_role }}</strong>
                            <span class="fw-semibold">{{ number_format($row->count) }}</span>
                        </div>
                        <div class="text-muted small mt-1 font-monospace">{{ $row->actor_id }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No audit events recorded yet.</p>
                @endforelse
                @if (count($byActor) > $sidePreviewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="most-active-actor-item" data-show-more-count="{{ count($byActor) - $sidePreviewLimit }}">
                        Show {{ count($byActor) - $sidePreviewLimit }} more
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

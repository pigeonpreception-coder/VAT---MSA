@extends('layouts.app')

@section('title', 'Audit trail')

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

<div class="card mb-4">
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
@endsection

@extends('layouts.app')

@section('title', $dispute->dispute_number)

@section('content')
<div class="mb-3">
    <a href="{{ route('disputes.index') }}" class="small">&larr; Back to disputes</a>
</div>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Dispute</div>
        <h1 class="h3 mb-1">{{ $dispute->dispute_number }}</h1>
        <p class="text-muted mb-0">{{ $taxpayer?->legal_name ?? 'Unknown taxpayer' }} &middot; {{ $taxpayer?->vat_number }}</p>
    </div>
    <x-status-badge :value="$dispute->status" type="status" />
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Grounds</div>
            <div class="card-body">
                <p class="mb-0" style="white-space: pre-wrap;">{{ $dispute->grounds }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Disputed resource</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Type</dt>
                    <dd class="col-sm-8">{{ ucwords(strtolower(str_replace('_', ' ', $dispute->disputed_resource_type))) }}</dd>
                    <dt class="col-sm-4">Resource ID</dt>
                    <dd class="col-sm-8 font-monospace">{{ $dispute->disputed_resource_id }}</dd>
                    @if ($dispute->audit_case_id)
                        <dt class="col-sm-4">Related audit case</dt>
                        <dd class="col-sm-8 font-monospace">{{ $dispute->audit_case_id }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Summary</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Disputed amount</span>
                    <strong>{{ $dispute->currency }} {{ number_format($dispute->disputed_amount_cents / 100, 2) }}</strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Filed</span>
                    <span>{{ $dispute->filed_at?->format('d M Y H:i') }}</span>
                </li>
                @if ($dispute->assigned_officer_id)
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Assigned officer</span>
                        <span class="font-monospace small">{{ $dispute->assigned_officer_id }}</span>
                    </li>
                @endif
                @if ($dispute->decided_at)
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Decided</span>
                        <span>{{ $dispute->decided_at->format('d M Y H:i') }}</span>
                    </li>
                @endif
            </ul>
            @if (! $dispute->assigned_officer_id)
                <div class="card-footer text-muted small">Awaiting review assignment. This dispute has no further workflow beyond filing in the current system.</div>
            @endif
        </div>
    </div>
</div>
@endsection

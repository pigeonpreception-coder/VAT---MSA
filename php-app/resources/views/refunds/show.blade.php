@extends('layouts.app')

@section('title', $claim['claim_number'])

@php
    $money = fn (int $cents) => $claim['currency'].' '.number_format($cents / 100, 2);
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Refund claim</div>
        <h1 class="h3 mb-1">{{ $claim['claim_number'] }}</h1>
        <p class="text-muted mb-0">{{ $claim['legal_name'] }} &middot; {{ $claim['vat_number'] }}</p>
    </div>
    <a href="{{ route('refunds.index') }}" class="btn btn-outline-secondary align-self-center">&larr; Back to refund claims</a>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
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

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">Claim record</div>
        <x-status-badge :value="$claim['status']" type="status" />
    </div>
    <dl class="card-body row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-0">
        <div class="col"><dt class="text-muted small">Amount claimed</dt><dd class="mb-0 fw-semibold">{{ $money($claim['amount_cents']) }}</dd></div>
        <div class="col"><dt class="text-muted small">Risk tier</dt><dd class="mb-0"><x-status-badge :value="$claim['risk_tier']" type="risk" /></dd></div>
        <div class="col"><dt class="text-muted small">Evidence status</dt><dd class="mb-0 fw-semibold">{{ $titleCase($claim['evidence_status']) }}</dd></div>
        <div class="col"><dt class="text-muted small">Requested</dt><dd class="mb-0 fw-semibold">{{ $dateTime($claim['requested_at']) }}</dd></div>
        <div class="col"><dt class="text-muted small">Approved</dt><dd class="mb-0 fw-semibold">{{ $dateTime($claim['approved_at']) }}</dd></div>
        @if ($claim['offset_amount_cents'] > 0)
            <div class="col"><dt class="text-muted small">Offset against debt</dt><dd class="mb-0 fw-semibold">{{ $money($claim['offset_amount_cents']) }}</dd></div>
            <div class="col"><dt class="text-muted small">Net payable</dt><dd class="mb-0 fw-semibold">{{ $claim['net_payable_cents'] === null ? '—' : $money($claim['net_payable_cents']) }}</dd></div>
        @endif
        <div class="col"><dt class="text-muted small">Source VAT return</dt><dd class="mb-0"><a href="{{ route('vat-returns.show', $claim['vat_return_version_id']) }}">View return</a></dd></div>
    </dl>
    @if ($claim['dispute_reason'])
        <div class="card-footer bg-transparent">
            <div class="text-muted small">Dispute reason</div>
            <div>{{ $claim['dispute_reason'] }}</div>
        </div>
    @endif
</div>

<div class="row g-3 mt-0">
    @if ($canReview && count($validActions))
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Review decision</h2>
                    <p class="text-muted small">Officer-only, maker-checker enforced -- you cannot review your own request, and a HIGH/CRITICAL-risk or payment-authorising decision requires a distinct reviewer from the immediately preceding step.</p>
                    <form method="POST" action="{{ route('refunds.transition.store', $claim['id']) }}">
                        @csrf
                        <div class="mb-2">
                            <label for="action" class="form-label">Action</label>
                            <select id="action" name="action" class="form-select @error('action') is-invalid @enderror" required>
                                @foreach ($validActions as $action)
                                    <option value="{{ $action }}" @selected(old('action') === $action)>{{ ucwords(strtolower(str_replace('_', ' ', $action))) }}</option>
                                @endforeach
                            </select>
                            @error('action')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-2">
                            <label for="findings" class="form-label">Findings</label>
                            <textarea id="findings" name="findings" class="form-control @error('findings') is-invalid @enderror" minlength="5" maxlength="2000" rows="2" required>{{ old('findings') }}</textarea>
                            @error('findings')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Submit decision</button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($canDispute)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Dispute this outcome</h2>
                    <p class="text-muted small">Only the original requester may dispute a rejected claim.</p>
                    <form method="POST" action="{{ route('refunds.dispute.store', $claim['id']) }}">
                        @csrf
                        <div class="mb-2">
                            <label for="dispute-findings" class="form-label">Reason for dispute</label>
                            <textarea id="dispute-findings" name="findings" class="form-control @error('findings') is-invalid @enderror" minlength="5" maxlength="2000" rows="2" required>{{ old('findings') }}</textarea>
                            @error('findings')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm">Submit dispute</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Eligibility checks</div>
        <div class="text-muted small">Evaluated once, frozen alongside the claim snapshot -- each its own explainable pass/fail, never a black-box score</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">The eligibility and advisory checks evaluated when this claim was submitted</caption>
            <thead>
                <tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Rationale</th></tr>
            </thead>
            <tbody>
                @foreach ($claim['checks'] as $check)
                    <tr>
                        <td>{{ $titleCase($check['check_code']) }}</td>
                        <td><x-status-badge :value="$check['status']" type="status" /></td>
                        <td class="text-muted small">{{ $check['rationale'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Transition history</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Every state-machine action recorded against this claim</caption>
            <thead>
                <tr><th scope="col">Action</th><th scope="col">From</th><th scope="col">To</th><th scope="col">By</th><th scope="col">When</th><th scope="col">Findings</th></tr>
            </thead>
            <tbody>
                @forelse ($claim['transitions'] as $transition)
                    <tr>
                        <td>{{ $titleCase($transition['action']) }}</td>
                        <td><x-status-badge :value="$transition['from_status']" type="status" /></td>
                        <td><x-status-badge :value="$transition['to_status']" type="status" /></td>
                        <td>{{ $transition['actor_name'] }}</td>
                        <td>{{ $dateTime($transition['occurred_at']) }}</td>
                        <td class="text-muted small">{{ $transition['findings'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No transitions recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

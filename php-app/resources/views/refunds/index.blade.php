@extends('layouts.app')

@section('title', 'Refund control')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? 'NAD').' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $total = collect($snapshot['refunds'])->sum('amount_cents');
    $blocked = collect($snapshot['refunds'])->filter(fn ($item) => str_starts_with((string) $item['status'], 'BLOCKED_'))->count();
    $approvedForPayment = collect($snapshot['refunds'])->where('status', 'APPROVED_FOR_PAYMENT')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Refund domain</div>
    <h1 class="h3 mb-1">Evidence, risk and payment authorisation</h1>
    <p class="text-muted mb-0">A negative draft return is not a payable refund. Eligibility, statutory filing acknowledgement, evidence review, risk review, supervisor approval and payment instruction remain separate controls.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Refund requests</span><span>R</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['refunds'])) }}</div>
            <div class="small text-muted">Preliminary and controlled records</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Requested value</span><span>N$</span></div>
            <div class="fs-2 fw-semibold">{{ $money((int) $total) }}</div>
            <div class="small text-muted">Not an approved payment amount</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Configuration blocks</span><span>B</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($blocked) }}</div>
            <div class="small {{ $blocked > 0 ? 'text-warning' : 'text-success' }}">No ITAS filing acknowledgement</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Approved for payment</span><span>P</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($approvedForPayment) }}</div>
            <div class="small text-muted">Requires separate payment boundary</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Refund workflow register</div>
        <div class="text-muted small">No auto-payment and no invented bank status</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Refund claims, their evidence and risk status, and requested amount</caption>
            <thead>
                <tr><th scope="col">Claim</th><th scope="col">Taxpayer</th><th scope="col">Period / version</th><th scope="col">Amount</th><th scope="col">Evidence</th><th scope="col">Risk</th><th scope="col">Status</th><th scope="col">Requested</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['refunds'] as $item)
                    <tr>
                        <td><strong>{{ $item['claim_number'] }}</strong><div class="text-muted small font-monospace">{{ $item['id'] }}</div></td>
                        <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</td>
                        <td>{{ $item['period_code'] }}<div class="text-muted small">Version {{ $item['version_number'] }}</div></td>
                        <td class="text-end">{{ $money($item['amount_cents'], $item['currency']) }}</td>
                        <td><x-status-badge :value="$item['evidence_status']" type="status" /></td>
                        <td><x-status-badge :value="$item['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                        <td>{{ $dateTime($item['requested_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No refund claims yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-info mt-3" role="status">
    <strong>Payment execution remains disabled by design.</strong><br>
    No banking or Treasury interface is configured. VAT-MSA can prepare a governed payment instruction only after the approved architecture's staged human controls and authoritative integration contract are satisfied.
</div>
@endsection

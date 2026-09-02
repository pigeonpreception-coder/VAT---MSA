@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $highRisk = collect($snapshot['risk_counts'])->whereIn('risk_level', ['HIGH', 'CRITICAL'])->sum('count');
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">{{ $isNationalScope ? 'National operations' : 'Taxpayer operations' }}</div>
        <h1 class="h3 mb-1">VAT transaction control centre</h1>
        <p class="text-muted mb-0">Live certification, ledger, reconciliation and compliance position for the controlled pilot.</p>
    </div>
    @can('permission', 'invoices:submit')
        <a href="#" class="btn btn-primary align-self-center">+ Submit invoice</a>
    @endcan
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Certified documents</span><span>#</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($snapshot['metrics']['invoice_count']) }}</div>
                <div class="small text-success">All records committed atomically</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Transaction value</span><span>N$</span></div>
                <div class="fs-2 fw-semibold">N$ {{ number_format($snapshot['metrics']['total_cents'] / 100, 2) }}</div>
                <div class="small text-muted">Gross fiscal value in the pilot ledger</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>VAT controlled</span><span>15</span></div>
                <div class="fs-2 fw-semibold">N$ {{ number_format($snapshot['metrics']['tax_cents'] / 100, 2) }}</div>
                <div class="small text-muted">Output VAT represented by certificates</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Open exceptions</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($snapshot['metrics']['exception_count']) }}</div>
                <div class="small {{ $highRisk > 0 ? 'text-warning' : 'text-success' }}">
                    {{ $highRisk }} high or critical risk item{{ $highRisk === 1 ? '' : 's' }}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Recent fiscal documents</div>
                    <div class="text-muted small">Latest certified invoice activity</div>
                </div>
                @can('permission', 'invoices:read')
                    <a href="#" class="btn btn-sm btn-outline-secondary">View all</a>
                @endcan
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr><th>Invoice</th><th>Supplier</th><th>Customer</th><th class="text-end">VAT</th><th>Status</th><th>Risk</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['recent_invoices'] as $invoice)
                            <tr>
                                <td>
                                    <strong>{{ $invoice['invoiceNumber'] }}</strong>
                                    <div class="text-muted small font-monospace">{{ $invoice['id'] }}</div>
                                </td>
                                <td>
                                    {{ $invoice['supplierName'] }}
                                    <div class="text-muted small">{{ $invoice['supplierVatNumber'] }}</div>
                                </td>
                                <td>{{ $invoice['customerName'] }}</td>
                                <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['taxCents'] / 100, 2) }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $invoice['status'] }}</span></td>
                                <td><span class="badge bg-light text-dark border">{{ $invoice['riskLevel'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No certified documents yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Evidence stream</div>
                <div class="text-muted small">Append-only operational audit trail</div>
            </div>
            <div class="card-body" style="max-height: 420px; overflow-y: auto;">
                @forelse ($snapshot['recent_audit'] as $event)
                    <div class="d-flex mb-3">
                        <span class="badge rounded-pill bg-primary mt-1 me-2" style="width: .5rem; height: .5rem; padding: 0;"></span>
                        <div class="flex-grow-1">
                            <strong>{{ ucwords(strtolower(str_replace('_', ' ', $event['action']))) }}</strong>
                            <p class="mb-0 text-muted small">{{ $event['resource_type'] }} &middot; <span class="font-monospace">{{ $event['resource_id'] }}</span></p>
                            <time class="text-muted small">{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d H:i') }}</time>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">
                        {{ $user->hasAppPermission('audit:read') ? 'No audit events recorded yet.' : 'Audit trail requires audit:read permission.' }}
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $highRisk = collect($snapshot['risk_counts'])->whereIn('risk_level', ['HIGH', 'CRITICAL'])->sum('count');
    $canReadInvoices = $user->hasAppPermission('invoices:read');
    $canReadAudit = $user->hasAppPermission('audit:read');
    $money = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
@endphp

@section('content')
<x-page-header title="Dashboard"
    :subtitle="$isNationalScope
        ? 'National VAT activity across all registered taxpayers.'
        : (($user->organisation()?->legal_name ?? 'Your organisation').' — VAT activity at a glance.')">
    @if ($canReadInvoices)
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary"><i class="bi bi-receipt" aria-hidden="true"></i> All invoices</a>
        </x-slot:actions>
    @endif
</x-page-header>

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Certified invoices" icon="receipt" :value="number_format($snapshot['metrics']['invoice_count'])" hint="Invoices accepted and certified" />
    </div>
    <div class="col">
        <x-stat-tile label="Invoice value" icon="cash-stack" :value="$money($snapshot['metrics']['total_cents'])" hint="Total value of certified invoices" />
    </div>
    <div class="col">
        <x-stat-tile label="Output VAT" icon="percent" :value="$money($snapshot['metrics']['tax_cents'])" hint="VAT charged on certified invoices" />
    </div>
    <div class="col">
        <x-stat-tile label="Open exceptions" icon="exclamation-triangle" :value="number_format($snapshot['metrics']['exception_count'])"
            :hint="$highRisk.' high or critical risk item'.($highRisk === 1 ? '' : 's')" :tone="$highRisk > 0 ? 'warning' : 'success'"
            :href="$user->hasAppPermission('exceptions:read') ? route('exceptions.index') : null" />
    </div>
</div>

<div class="row g-3">
    <div class="{{ $canReadAudit ? 'col-xl-8' : 'col-12' }}">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Recent invoices</div>
                    <div class="text-muted small">The latest certified invoices</div>
                </div>
                @if ($canReadInvoices)
                    <a href="{{ route('invoices.index') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">The six most recently certified invoices</caption>
                    <thead>
                        <tr><th scope="col">Invoice</th><th scope="col">Supplier / customer</th><th scope="col" class="text-end">VAT</th><th scope="col">Status</th><th scope="col">Risk</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['recent_invoices'] as $invoice)
                            <tr>
                                <td class="text-nowrap">
                                    @if ($canReadInvoices)
                                        <a href="{{ route('invoices.show', $invoice['id']) }}">{{ $invoice['invoiceNumber'] }}</a>
                                    @else
                                        <span class="fw-semibold">{{ $invoice['invoiceNumber'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $invoice['supplierName'] }}</div>
                                    <div class="text-muted small">to {{ $invoice['customerName'] }}</div>
                                </td>
                                <td class="text-end text-nowrap">{{ $invoice['currency'] }} {{ number_format($invoice['taxCents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                                <td><x-status-badge :value="$invoice['riskLevel']" type="risk" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">No certified invoices yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($canReadAudit)
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">Recent activity</div>
                        <div class="text-muted small">Latest events in the audit trail</div>
                    </div>
                    <a href="{{ route('audit-trail.index') }}" class="btn btn-sm btn-outline-secondary">Audit trail</a>
                </div>
                <div class="card-body activity-feed">
                    @forelse ($snapshot['recent_audit'] as $event)
                        <div class="activity-item">
                            <span class="activity-dot" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <div class="fw-semibold">@humanize($event['action'])</div>
                                <div class="text-muted small">
                                    @humanize($event['resource_type']) &middot;
                                    <time datetime="{{ $event['occurred_at'] }}">{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('d M Y, H:i') }}</time>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">No activity recorded yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

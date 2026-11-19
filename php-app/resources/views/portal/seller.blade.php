@extends('layouts.app')

@section('title', 'Seller portal')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? $tenantCurrencyCode).' '.number_format($cents / 100, 2));
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $outputVat = collect($snapshot['vat']['periods'])->sum(fn ($period) => (int) ($period['output_tax_cents'] ?? 0));
    $exceptions = $snapshot['dashboard']['metrics']['exception_count'];
@endphp

@section('content')
<x-page-header title="Seller portal" :back="route('portals.index')" back-label="Portals"
    subtitle="Sales, quotations and the output VAT position for your organisation." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Invoices" icon="receipt" :value="number_format($snapshot['dashboard']['metrics']['invoice_count'])" :hint="$money($snapshot['dashboard']['metrics']['total_cents']).' total value'" />
    </div>
    <div class="col">
        <x-stat-tile label="Output VAT" icon="percent" :value="$money($outputVat)" hint="Across your VAT periods" />
    </div>
    <div class="col">
        <x-stat-tile label="Quotations" icon="file-earmark-text" :value="number_format($snapshot['quotations']['count'])" :hint="$money($snapshot['quotations']['quoted_value_cents']).' quoted'" />
    </div>
    <div class="col">
        <x-stat-tile label="Exceptions" icon="exclamation-triangle" :value="number_format($exceptions)" hint="Resolve before filing your return" :tone="$exceptions > 0 ? 'warning' : 'success'" />
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Recent invoices</div>
        <div class="text-muted small">Latest certified sales invoices</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Recently certified invoices, their customer and VAT status</caption>
            <thead>
                <tr><th scope="col">Invoice</th><th scope="col">Supplier</th><th scope="col">Customer</th><th scope="col">Issue date</th><th scope="col" class="text-end">VAT</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['dashboard']['recent_invoices'] as $item)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $item['invoiceNumber'] }}</td>
                        <td>{{ $item['supplierName'] }}</td>
                        <td>{{ $item['customerName'] }}</td>
                        <td class="text-nowrap">{{ $date($item['issueDate']) }}</td>
                        <td class="text-end text-nowrap">{{ $money($item['taxCents'], $item['currency']) }}</td>
                        <td class="text-end text-nowrap">{{ $money($item['totalCents'], $item['currency']) }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">No certified invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

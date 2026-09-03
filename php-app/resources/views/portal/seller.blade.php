@extends('layouts.app')

@section('title', 'Seller portal')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? 'NAD').' '.number_format($cents / 100, 2));
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $outputVat = collect($snapshot['vat']['periods'])->sum(fn ($period) => (int) ($period['output_tax_cents'] ?? 0));
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Seller workspace</div>
    <h1 class="h3 mb-1">Sales, certification and output VAT position</h1>
    <p class="text-muted mb-0">The Seller experience prioritises customers, quotations, certified sales, inventory and return impact while retaining the same canonical organisation and fiscal records.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Invoices</span><span>I</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['dashboard']['metrics']['invoice_count']) }}</div>
            <div class="small text-muted">{{ $money($snapshot['dashboard']['metrics']['total_cents']) }} gross value</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Output VAT</span><span>V</span></div>
            <div class="fs-2 fw-semibold">{{ $money($outputVat) }}</div>
            <div class="small text-muted">Across visible VAT periods</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Quotations</span><span>Q</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['quotations']['count']) }}</div>
            <div class="small text-muted">{{ $money($snapshot['quotations']['quoted_value_cents']) }} pipeline</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Exceptions</span><span>!</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['dashboard']['metrics']['exception_count']) }}</div>
            <div class="small {{ $snapshot['dashboard']['metrics']['exception_count'] > 0 ? 'text-warning' : 'text-success' }}">Requires controlled resolution</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Recent seller transaction activity</div>
        <div class="text-muted small">Certification state is explicit</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Recently certified invoices, their customer and VAT status</caption>
            <thead>
                <tr><th scope="col">Invoice</th><th scope="col">Supplier</th><th scope="col">Customer</th><th scope="col">Issue date</th><th scope="col" class="text-end">Tax</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['dashboard']['recent_invoices'] as $item)
                    <tr>
                        <td><strong>{{ $item['invoiceNumber'] }}</strong></td>
                        <td>{{ $item['supplierName'] }}</td>
                        <td>{{ $item['customerName'] }}</td>
                        <td>{{ $date($item['issueDate']) }}</td>
                        <td class="text-end">{{ $money($item['taxCents'], $item['currency']) }}</td>
                        <td class="text-end">{{ $money($item['totalCents'], $item['currency']) }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No certified documents yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

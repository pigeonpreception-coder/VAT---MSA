@extends('layouts.app')

@section('title', 'Buyer portal')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? 'NAD').' '.number_format($cents / 100, 2));
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $inputVat = collect($snapshot['vat']['periods'])->sum(fn ($period) => (int) ($period['input_tax_cents'] ?? 0));
    $unmatched = collect($snapshot['vat']['reconciliation'])->filter(fn ($item) => $item['status'] !== 'MATCHED')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Buyer workspace</div>
    <h1 class="h3 mb-1">Purchases, input VAT and evidence requiring action</h1>
    <p class="text-muted mb-0">This projection omits NamRA internal risk and technical administration. It shows authorised supplier transactions, business expenses, reconciliation, return impact and evidence state.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Expenses</span><span>E</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['expenses'])) }}</div>
            <div class="small text-muted">{{ $money($snapshot['metrics']['expense_value_cents']) }} recorded</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Input VAT</span><span>V</span></div>
            <div class="fs-2 fw-semibold">{{ $money($inputVat) }}</div>
            <div class="small text-muted">Across visible VAT periods</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Unmatched items</span><span>!</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($unmatched) }}</div>
            <div class="small {{ $unmatched > 0 ? 'text-warning' : 'text-success' }}">Review before claiming</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Quarantined evidence</span><span>D</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['documents']['quarantined']) }}</div>
            <div class="small text-muted">Unavailable until clean scan</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Recent purchase and expense evidence</div>
        <div class="text-muted small">Amounts remain linked to their source and workflow status</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Business expenses, their supplier, category and approval status</caption>
            <thead>
                <tr><th scope="col">Expense</th><th scope="col">Supplier</th><th scope="col">Category</th><th scope="col">Date</th><th scope="col" class="text-end">Tax</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['expenses'] as $item)
                    <tr>
                        <td><strong>{{ $item['expense_number'] }}</strong></td>
                        <td>{{ $item['supplier_name'] ?? 'Unassigned' }}</td>
                        <td>{{ $item['category_name'] }}</td>
                        <td>{{ $date($item['expense_date']) }}</td>
                        <td class="text-end">{{ $money($item['tax_cents'], $item['currency']) }}</td>
                        <td class="text-end">{{ $money($item['total_cents'], $item['currency']) }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No expenses recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

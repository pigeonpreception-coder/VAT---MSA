@extends('layouts.app')

@section('title', 'Buyer portal')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? $tenantCurrencyCode).' '.number_format($cents / 100, 2));
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $inputVat = collect($snapshot['vat']['periods'])->sum(fn ($period) => (int) ($period['input_tax_cents'] ?? 0));
    $unmatched = collect($snapshot['vat']['reconciliation'])->filter(fn ($item) => $item['status'] !== 'MATCHED')->count();
@endphp

@section('content')
<x-page-header title="Buyer portal" :back="route('portals.index')" back-label="Portals"
    subtitle="Purchases, expenses, input VAT and the supporting evidence that needs your attention." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Expenses" icon="wallet2" :value="number_format(count($snapshot['expenses']))" :hint="$money($snapshot['metrics']['expense_value_cents']).' recorded'" />
    </div>
    <div class="col">
        <x-stat-tile label="Input VAT" icon="percent" :value="$money($inputVat)" hint="Across your VAT periods" />
    </div>
    <div class="col">
        <x-stat-tile label="Unmatched items" icon="link-45deg" :value="number_format($unmatched)" hint="Review before claiming input VAT" :tone="$unmatched > 0 ? 'warning' : 'success'" />
    </div>
    <div class="col">
        <x-stat-tile label="Quarantined documents" icon="file-earmark-lock" :value="number_format($snapshot['documents']['quarantined'])" hint="Held until a clean virus scan" />
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Recent expenses</div>
        <div class="text-muted small">Purchases and expenses with their approval status</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Business expenses, their supplier, category and approval status</caption>
            <thead>
                <tr><th scope="col">Expense</th><th scope="col">Supplier</th><th scope="col">Category</th><th scope="col">Date</th><th scope="col" class="text-end">VAT</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['expenses'] as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item['expense_number'] }}</td>
                        <td>{{ $item['supplier_name'] ?? 'Unassigned' }}</td>
                        <td>{{ $item['category_name'] }}</td>
                        <td class="text-nowrap">{{ $date($item['expense_date']) }}</td>
                        <td class="text-end text-nowrap">{{ $money($item['tax_cents'], $item['currency']) }}</td>
                        <td class="text-end text-nowrap">{{ $money($item['total_cents'], $item['currency']) }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">No expenses recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Supplier Ledger')

@php
    $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Accounting & Finance</div>
        <h1 class="h3 mb-1">Supplier Ledger</h1>
        <p class="text-muted mb-0">Per-supplier posted balances derived from approved expenses -- the maker-checker equivalent of a posted general-ledger transaction in this platform's lighter accounting model.</p>
    </div>
    <a href="{{ route('accounting.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to Accounting</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Suppliers with activity</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($summary['suppliers'])) }}</div>
            <div class="small text-muted">{{ number_format($suppliers->count()) }} registered supplier{{ $suppliers->count() === 1 ? '' : 's' }} in total</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total posted balance</div>
            <div class="fs-2 fw-semibold">{{ $fmt($summary['total_posted_cents']) }}</div>
            <div class="small text-success">Approved expenses</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Pending approval</div>
            <div class="fs-2 fw-semibold">{{ $fmt($summary['total_pending_cents']) }}</div>
            <div class="small text-muted">Not yet a recognised liability</div>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="fw-semibold">Supplier balances</div>
        <div class="text-muted small">Posted = approved expenses; pending = submitted, awaiting approval</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Supplier balances, posted and pending totals</caption>
            <thead>
                <tr>
                    <th scope="col">Supplier</th>
                    <th scope="col" class="text-end">Posted count</th>
                    <th scope="col" class="text-end">Posted balance</th>
                    <th scope="col" class="text-end">Pending count</th>
                    <th scope="col" class="text-end">Pending amount</th>
                    <th scope="col">Last activity</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($summary['suppliers'] as $row)
                    <tr class="{{ $selectedSupplierId === $row['supplier_party_id'] ? 'table-active' : '' }}">
                        <td>
                            {{ $row['display_name'] }}
                            <div class="text-muted small">{{ $row['vat_number'] ?? '—' }}</div>
                        </td>
                        <td class="text-end">{{ $row['posted_count'] }}</td>
                        <td class="text-end fw-semibold">{{ $fmt($row['posted_total_cents']) }}</td>
                        <td class="text-end">{{ $row['pending_count'] }}</td>
                        <td class="text-end">{{ $fmt($row['pending_total_cents']) }}</td>
                        <td>{{ $row['last_expense_date'] }}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('accounting.supplier-ledger', ['supplier_id' => $row['supplier_party_id']]) }}">View statement</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No supplier expenses have been posted yet.</td></tr>
                @endforelse
                @if ($summary['unassigned']['posted_count'] > 0 || $summary['unassigned']['pending_count'] > 0)
                    <tr class="table-light">
                        <td><em>Unassigned (no supplier recorded)</em></td>
                        <td class="text-end">{{ $summary['unassigned']['posted_count'] }}</td>
                        <td class="text-end fw-semibold">{{ $fmt($summary['unassigned']['posted_total_cents']) }}</td>
                        <td class="text-end">{{ $summary['unassigned']['pending_count'] }}</td>
                        <td class="text-end">{{ $fmt($summary['unassigned']['pending_total_cents']) }}</td>
                        <td>—</td>
                        <td></td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('accounting.supplier-ledger') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="supplier_id" class="form-label small mb-0">Supplier statement</label>
                <select id="supplier_id" name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Select a supplier…</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected($selectedSupplierId === $supplier->id)>{{ $supplier->display_name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    @if ($statement)
        <div class="card-body pb-0">
            <h2 class="h6">{{ $statement['supplier']['display_name'] }}</h2>
            <p class="text-muted small mb-0">
                {{ $statement['supplier']['legal_name'] ?? $statement['supplier']['display_name'] }}
                @if ($statement['supplier']['vat_number'])
                    &middot; VAT {{ $statement['supplier']['vat_number'] }}
                @endif
                &middot; <x-status-badge :value="$statement['supplier']['status']" type="status" />
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Expenses posted against this supplier with a running balance</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Expense</th>
                        <th scope="col">Category</th>
                        <th scope="col">Description</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Running balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statement['lines'] as $line)
                        <tr>
                            <td>{{ $line['expense_date'] }}</td>
                            <td class="font-monospace small">{{ $line['expense_number'] }}</td>
                            <td>{{ $line['category_name'] }}</td>
                            <td>{{ $line['description'] }}</td>
                            <td><x-status-badge :value="$line['status']" type="status" /></td>
                            <td class="text-end">{{ $fmt($line['total_cents']) }}</td>
                            <td class="text-end">{{ $line['running_balance_cents'] !== null ? $fmt($line['running_balance_cents']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No expenses have been posted against this supplier.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="fw-semibold table-light">
                        <td colspan="6" class="text-end">Posted balance</td>
                        <td class="text-end">{{ $fmt($statement['posted_balance_cents']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="card-body text-center text-muted py-4">Select a supplier above to view their full statement.</div>
    @endif
</div>
@endsection

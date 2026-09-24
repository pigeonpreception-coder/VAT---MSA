@extends('layouts.app')

@section('title', 'Customer Ledger')

@php
    $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Accounting & Finance</div>
        <h1 class="h3 mb-1">Customer Ledger</h1>
        <p class="text-muted mb-0">Per-customer posted balances derived from converted quotations -- a converted quotation carries a real certified invoice, the receivables-side counterpart of an approved expense's posted balance in this platform's lighter accounting model.</p>
    </div>
    <a href="{{ route('accounting.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to Accounting</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Customers with activity</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($summary['customers'])) }}</div>
            <div class="small text-muted">{{ number_format($customers->count()) }} registered customer{{ $customers->count() === 1 ? '' : 's' }} in total</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total posted balance</div>
            <div class="fs-2 fw-semibold">{{ $fmt($summary['total_posted_cents']) }}</div>
            <div class="small text-success">Converted quotations (certified invoices)</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Pending conversion</div>
            <div class="fs-2 fw-semibold">{{ $fmt($summary['total_pending_cents']) }}</div>
            <div class="small text-muted">Accepted, not yet invoiced</div>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="fw-semibold">Customer balances</div>
        <div class="text-muted small">Posted = converted quotations; pending = accepted, awaiting conversion to an invoice</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Customer balances, posted and pending totals</caption>
            <thead>
                <tr>
                    <th scope="col">Customer</th>
                    <th scope="col" class="text-end">Posted count</th>
                    <th scope="col" class="text-end">Posted balance</th>
                    <th scope="col" class="text-end">Pending count</th>
                    <th scope="col" class="text-end">Pending amount</th>
                    <th scope="col">Last activity</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($summary['customers'] as $row)
                    <tr class="{{ $selectedCustomerId === $row['customer_party_id'] ? 'table-active' : '' }}">
                        <td>
                            {{ $row['display_name'] }}
                            <div class="text-muted small">{{ $row['vat_number'] ?? '—' }}</div>
                        </td>
                        <td class="text-end">{{ $row['posted_count'] }}</td>
                        <td class="text-end fw-semibold">{{ $fmt($row['posted_total_cents']) }}</td>
                        <td class="text-end">{{ $row['pending_count'] }}</td>
                        <td class="text-end">{{ $fmt($row['pending_total_cents']) }}</td>
                        <td>{{ $row['last_quotation_date'] }}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('accounting.customer-ledger', ['customer_id' => $row['customer_party_id']]) }}">View statement</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No customer quotations have been converted or accepted yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('accounting.customer-ledger') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="customer_id" class="form-label small mb-0">Customer statement</label>
                <select id="customer_id" name="customer_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Select a customer…</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected($selectedCustomerId === $customer->id)>{{ $customer->display_name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    @if ($statement)
        <div class="card-body pb-0">
            <h2 class="h6">{{ $statement['customer']['display_name'] }}</h2>
            <p class="text-muted small mb-0">
                {{ $statement['customer']['legal_name'] ?? $statement['customer']['display_name'] }}
                @if ($statement['customer']['vat_number'])
                    &middot; VAT {{ $statement['customer']['vat_number'] }}
                @endif
                &middot; <x-status-badge :value="$statement['customer']['status']" type="status" />
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Quotations raised against this customer with a running balance</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Quotation</th>
                        <th scope="col">Notes</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Running balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statement['lines'] as $line)
                        <tr>
                            <td>{{ $line['issue_date'] }}</td>
                            <td class="font-monospace small">
                                {{ $line['quotation_number'] }}
                                @if ($line['converted_invoice_id'])
                                    <div class="text-muted">Invoice {{ $line['converted_invoice_id'] }}</div>
                                @endif
                            </td>
                            <td>{{ $line['notes'] ?? '—' }}</td>
                            <td><x-status-badge :value="$line['status']" type="status" /></td>
                            <td class="text-end">{{ $fmt($line['total_cents']) }}</td>
                            <td class="text-end">{{ $line['running_balance_cents'] !== null ? $fmt($line['running_balance_cents']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No quotations have been raised against this customer.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="fw-semibold table-light">
                        <td colspan="5" class="text-end">Posted balance</td>
                        <td class="text-end">{{ $fmt($statement['posted_balance_cents']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="card-body text-center text-muted py-4">Select a customer above to view their full statement.</div>
    @endif
</div>
@endsection

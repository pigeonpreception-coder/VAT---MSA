@extends('layouts.app')

@section('title', 'Sales and quotations')

@php
    $todayIso = now()->toDateString();
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Commercial domain</div>
        <h1 class="h3 mb-1">Parties, products and quotations</h1>
        <p class="text-muted mb-0">Tenant-scoped commercial records feed invoicing without bypassing fiscal certification. Quotation totals and VAT are calculated from immutable integer inputs.</p>
    </div>
    <a href="{{ route('parties.index') }}" class="btn btn-secondary">Manage customers &amp; suppliers</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Business parties</div>
            <div class="fs-2 fw-semibold">{{ number_format($partyCount) }}</div>
            <div class="small text-muted">Customer and supplier relationships</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Quotations</div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['total_count']) }}</div>
            <div class="small text-muted">Versioned commercial offers</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Quoted value</div>
            <div class="fs-2 fw-semibold">N$ {{ number_format($quotedValueCents / 100, 2) }}</div>
            <div class="small text-muted">Issued, accepted and converted</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Catalog products</div>
            <div class="fs-2 fw-semibold">{{ number_format($products->count()) }}</div>
            <div class="small text-muted">Controlled tax categories and rates</div>
        </div></div>
    </div>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Quotation needs attention.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Quotation register</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Quotations, their customer, dates, amounts, status and available action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Quotation</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Issue / validity</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['quotations'] as $item)
                            @php $overdue = $item['valid_until'] < $todayIso; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $item['quotation_number'] }}</strong>
                                    <div class="text-muted small font-monospace">{{ $item['id'] }}</div>
                                </td>
                                <td>{{ $item['customer_name'] ?? '—' }}</td>
                                <td>
                                    {{ $item['issue_date'] }}
                                    <div class="text-muted small">to {{ $item['valid_until'] }}</div>
                                </td>
                                <td>{{ $item['currency'] }} {{ number_format($item['total_cents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                                <td style="min-width: 14rem;">
                                    @if ($item['status'] === 'CONVERTED' && $item['converted_invoice_id'])
                                        <a href="{{ route('invoices.show', $item['converted_invoice_id']) }}" class="btn btn-sm btn-outline-secondary">View invoice</a>
                                    @elseif ($item['status'] === 'DRAFT')
                                        @if ($canManageQuotations)
                                            <form method="POST" action="{{ route('quotations.send', $item['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">Send</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Read only</span>
                                        @endif
                                    @elseif ($item['status'] === 'ISSUED')
                                        @if (! $canManageQuotations)
                                            <span class="text-muted">Read only</span>
                                        @elseif ($overdue)
                                            <form method="POST" action="{{ route('quotations.expire', $item['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Expire</button>
                                            </form>
                                        @else
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('quotations.accept', $item['id']) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Accept</button>
                                                </form>
                                                <a href="{{ route('quotations.edit', $item['id']) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                                <form method="POST" action="{{ route('quotations.reject', $item['id']) }}" onsubmit="return quotationRejectPrompt(this);">
                                                    @csrf
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                                </form>
                                            </div>
                                        @endif
                                    @elseif ($item['status'] === 'ACCEPTED')
                                        @if ($canConvertQuotations)
                                            <form method="POST" action="{{ route('quotations.convert', $item['id']) }}" class="d-flex gap-1">
                                                @csrf
                                                <input type="text" class="form-control form-control-sm font-monospace" name="invoice_number" aria-label="Invoice number" required minlength="2" maxlength="100" placeholder="INV-2026-0001">
                                                <input type="date" class="form-control form-control-sm" name="issue_date" aria-label="Invoice issue date" required min="{{ $item['issue_date'] }}" value="{{ $todayIso }}">
                                                <button type="submit" class="btn btn-sm btn-primary text-nowrap">Convert</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Read only</span>
                                        @endif
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No quotations on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Issue quotation</div>
                <div class="text-muted small">Standard Namibia pilot VAT rate: 15%</div>
            </div>
            <div class="card-body">
                @if ($customers->isEmpty())
                    <p class="text-muted mb-0">Register an active customer before issuing a quotation. <a href="{{ route('parties.index') }}">Manage customers &amp; suppliers</a>.</p>
                @else
                    <form method="POST" action="{{ route('quotations.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="quotation_number" class="form-label">Quotation number</label>
                            <input type="text" class="form-control font-monospace" id="quotation_number" name="quotation_number" required maxlength="40" placeholder="QUO-2026-0002" value="{{ old('quotation_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="customer_party_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_party_id" name="customer_party_id" required>
                                <option value="" disabled selected>Select customer</option>
                                @foreach ($customers as $party)
                                    <option value="{{ $party['id'] }}" @selected(old('customer_party_id') === $party['id'])>{{ $party['display_name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="issue_date" class="form-label">Issue date</label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date" required value="{{ old('issue_date') }}">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="valid_until" class="form-label">Valid until</label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required value="{{ old('valid_until') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="product_id" class="form-label">Catalog product</label>
                            <select class="form-select" id="product_id" name="product_id">
                                <option value="">Custom line</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected(old('product_id') === $product->id)>{{ $product->sku }} — {{ $product->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Line description</label>
                            <input type="text" class="form-control" id="description" name="description" required maxlength="500" value="{{ old('description') }}">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="quantity" class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" required min="1" step="1" value="{{ old('quantity', 1) }}">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="unit_code" class="form-label">Unit code</label>
                                <input type="text" class="form-control" id="unit_code" name="unit_code" required maxlength="12" value="{{ old('unit_code', 'EA') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="unit_price_cents" class="form-label">Unit price (cents)</label>
                            <input type="number" class="form-control" id="unit_price_cents" name="unit_price_cents" required min="0" step="1" value="{{ old('unit_price_cents') }}">
                            <div class="form-text">Enter N$ 100.00 as 10000. VAT is calculated server-side.</div>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" class="form-control" id="notes" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Issue quotation</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    function quotationRejectPrompt(form) {
        var reason = window.prompt("Record the customer's quotation rejection reason.");
        if (!reason || !reason.trim()) return false;
        form.querySelector('input[name=reason]').value = reason.trim();
        return true;
    }
</script>
@endsection

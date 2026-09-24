@extends('layouts.app')

@section('title', 'New Credit Note')

@php
    // Currency-code prefix, matching invoices/show.blade.php's own $money
    // helper convention -- not the "N$" shorthand the Business/Accounting
    // domain views use, since an invoice's own currency is per-document,
    // not always the viewer's own tenant currency.
    $fmt = fn (int $cents) => trim(($selected->currency ?? $tenantCurrencyCode).' '.number_format($cents / 100, 2));
    $todayIso = now()->toDateString();
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">New Registration</div>
        <h1 class="h3 mb-1">New Credit Note</h1>
        <p class="text-muted mb-0">Issue a credit note against one of your own certified invoices. Supplier and customer are carried over from the original invoice automatically.</p>
    </div>
    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to invoices</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('new-registration.credit-note') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label for="invoice_id" class="form-label">Original invoice</label>
                <select id="invoice_id" name="invoice_id" class="form-select" onchange="this.form.submit()">
                    <option value="" @selected(! $selected)>Select an invoice to credit</option>
                    @foreach ($originals as $invoice)
                        <option value="{{ $invoice->id }}" @selected($selected && $selected->id === $invoice->id)>
                            {{ $invoice->invoice_number }} &middot; {{ $invoice->customer_name }} &middot; {{ $invoice->issue_date->toDateString() }} &middot; {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}
                        </option>
                    @endforeach
                </select>
                @if ($originals->isEmpty())
                    <div class="form-text">No eligible original invoices found. Only your own certified tax invoices can be credited.</div>
                @endif
            </div>
        </form>
    </div>
</div>

@if ($selected)
    <div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Customer</div>
                <div class="fs-5 fw-semibold">{{ $selected->customer_name }}</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Original total</div>
                <div class="fs-5 fw-semibold">{{ $selected->currency }} {{ number_format($selected->total_cents / 100, 2) }}</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Remaining creditable</div>
                <div class="fs-5 fw-semibold {{ $remainingCents <= 0 ? 'text-danger' : '' }}">{{ $selected->currency }} {{ number_format($remainingCents / 100, 2) }}</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">Credit note details</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('new-registration.credit-note.store') }}">
                @csrf
                <x-idempotency-key />
                <input type="hidden" name="invoice_id" value="{{ $selected->id }}">

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="reason_code" class="form-label">Reason code</label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="reason_code" name="reason_code" required maxlength="40" placeholder="PRICING_ERROR" value="{{ old('reason_code') }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="issue_date" class="form-label">Issue date</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date" required min="{{ $selected->issue_date->toDateString() }}" value="{{ old('issue_date', $todayIso) }}">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="reason" class="form-label">Reason</label>
                    <textarea class="form-control" id="reason" name="reason" required minlength="5" maxlength="1000" rows="2">{{ old('reason') }}</textarea>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle">
                        <caption class="visually-hidden">Original invoice lines with an editable quantity to credit against each</caption>
                        <thead>
                            <tr>
                                <th scope="col">Line</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Original qty</th>
                                <th scope="col" class="text-end">Unit price</th>
                                <th scope="col" class="text-end">Tax</th>
                                <th scope="col" style="width: 10rem;">Quantity to credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $line)
                                <tr>
                                    <td>{{ $line->line_number }}</td>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-end">{{ $line->quantity }} {{ $line->unit_code }}</td>
                                    <td class="text-end">{{ $fmt($line->unit_price_cents) }}</td>
                                    <td class="text-end"><x-status-badge :value="$line->tax_category" type="status" /></td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="form-control form-control-sm" name="credit_quantity[{{ $line->id }}]" placeholder="0" value="{{ old('credit_quantity.'.$line->id, '0') }}">
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">This invoice has no lines.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="form-text mb-3">Enter the quantity to credit for each line you want to reverse; leave the rest at 0. Amounts are recorded as a reduction automatically.</div>

                <button type="submit" class="btn btn-primary">Issue credit note</button>
            </form>
        </div>
    </div>
@endif
@endsection

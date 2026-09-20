@extends('layouts.app')

@section('title', 'New Debit Note')

@php
    $todayIso = now()->toDateString();
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">New Registration</div>
        <h1 class="h3 mb-1">New Debit Note</h1>
        <p class="text-muted mb-0">Issue a debit note against one of your own certified invoices for an additional charge. Supplier and customer are carried over from the original invoice automatically.</p>
    </div>
    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to invoices</a>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
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
        <form method="GET" action="{{ route('new-registration.debit-note') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label for="invoice_id" class="form-label">Original invoice</label>
                <select id="invoice_id" name="invoice_id" class="form-select" onchange="this.form.submit()">
                    <option value="" @selected(! $selected)>Select an invoice to debit</option>
                    @foreach ($originals as $invoice)
                        <option value="{{ $invoice->id }}" @selected($selected && $selected->id === $invoice->id)>
                            {{ $invoice->invoice_number }} &middot; {{ $invoice->customer_name }} &middot; {{ $invoice->issue_date->toDateString() }} &middot; {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}
                        </option>
                    @endforeach
                </select>
                @if ($originals->isEmpty())
                    <div class="form-text">No eligible original invoices found. Only your own certified tax invoices can be debited.</div>
                @endif
            </div>
        </form>
    </div>
</div>

@if ($selected)
    <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
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
    </div>

    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">Debit note details</div>
            <div class="text-muted small">Standard Namibia pilot VAT rate: 15%</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('new-registration.debit-note.store') }}">
                @csrf
                <x-idempotency-key />
                <input type="hidden" name="invoice_id" value="{{ $selected->id }}">

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="reason_code" class="form-label">Reason code</label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="reason_code" name="reason_code" required maxlength="40" placeholder="ADDITIONAL_CHARGE" value="{{ old('reason_code') }}">
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

                <div class="mb-3">
                    <label for="description" class="form-label">Line description</label>
                    <input type="text" class="form-control" id="description" name="description" required maxlength="1000" value="{{ old('description') }}">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" required min="0.000001" step="any" value="{{ old('quantity', 1) }}">
                    </div>
                    <div class="col-6 mb-3">
                        <label for="unit_code" class="form-label">Unit code</label>
                        <input type="text" class="form-control" id="unit_code" name="unit_code" required maxlength="12" value="{{ old('unit_code', 'EA') }}">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="unit_price_cents" class="form-label">Unit price (cents)</label>
                    <input type="number" class="form-control" id="unit_price_cents" name="unit_price_cents" required min="1" step="1" value="{{ old('unit_price_cents') }}">
                    <div class="form-text">Enter N$ 100.00 as 10000. VAT is calculated server-side.</div>
                </div>

                <button type="submit" class="btn btn-primary">Issue debit note</button>
            </form>
        </div>
    </div>
@endif
@endsection

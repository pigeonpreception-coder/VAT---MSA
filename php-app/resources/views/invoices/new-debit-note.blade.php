@extends('layouts.app')

@section('title', 'New Debit Note')

@php
    $todayIso = now()->toDateString();
@endphp

@section('content')
<x-page-header title="New debit note" :back="route('invoices.index')" back-label="All invoices"
    subtitle="Add a further charge to a certified invoice you issued. The supplier and customer are copied from the original invoice." />

<x-form-errors />

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">1. Choose the original invoice</div>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('new-registration.debit-note') }}" class="row g-2 align-items-end">
            <div class="col-lg-8">
                <label for="invoice_id" class="form-label">Original invoice</label>
                <select id="invoice_id" name="invoice_id" class="form-select" onchange="this.form.submit()">
                    <option value="" @selected(! $selected)>Select an invoice to debit</option>
                    @foreach ($originals as $invoice)
                        <option value="{{ $invoice->id }}" @selected($selected && $selected->id === $invoice->id)>
                            {{ $invoice->invoice_number }} &middot; {{ $invoice->customer_name }} &middot; {{ $invoice->issue_date->format('d M Y') }} &middot; {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}
                        </option>
                    @endforeach
                </select>
                @if ($originals->isEmpty())
                    <div class="form-text">You have no certified invoices that can be debited. Only invoices your organisation issued are listed.</div>
                @endif
            </div>
        </form>
    </div>
</div>

@if ($selected)
    <div class="row row-cols-1 row-cols-sm-2 g-3 mb-3">
        <div class="col"><x-stat-tile label="Customer" :value="$selected->customer_name" /></div>
        <div class="col"><x-stat-tile label="Original total" :value="$selected->currency.' '.number_format($selected->total_cents / 100, 2)" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">2. Debit note details</div>
            <div class="text-muted small">VAT is added automatically at the standard rate</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('new-registration.debit-note.store') }}">
                @csrf
                <x-idempotency-key />
                <input type="hidden" name="invoice_id" value="{{ $selected->id }}">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="reason_code" class="form-label">Reason code</label>
                        <input type="text" class="form-control text-uppercase" id="reason_code" name="reason_code" list="debit-reason-codes" required maxlength="40" placeholder="e.g. ADDITIONAL_CHARGE" value="{{ old('reason_code') }}">
                        @include('invoices.partials.reason-codes', ['id' => 'debit-reason-codes', 'codes' => ['ADDITIONAL_CHARGE', 'PRICING_ERROR', 'UNDERBILLED_QUANTITY', 'LATE_PAYMENT_FEE']])
                    </div>
                    <div class="col-md-4">
                        <label for="issue_date" class="form-label">Issue date</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date" required min="{{ $selected->issue_date->toDateString() }}" value="{{ old('issue_date', $todayIso) }}">
                    </div>
                    <div class="col-12">
                        <label for="reason" class="form-label">Reason</label>
                        <textarea class="form-control" id="reason" name="reason" required minlength="5" maxlength="1000" rows="2" placeholder="Explain why the additional charge is being raised">{{ old('reason') }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="description" class="form-label">Line description</label>
                        <input type="text" class="form-control" id="description" name="description" required maxlength="1000" value="{{ old('description') }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" required min="0.000001" step="any" value="{{ old('quantity', 1) }}">
                    </div>
                    <div class="col-6 col-md-1">
                        <label for="unit_code" class="form-label">Unit</label>
                        <input type="text" class="form-control" id="unit_code" name="unit_code" required maxlength="12" value="{{ old('unit_code', 'EA') }}">
                    </div>
                    <div class="col-md-3">
                        <label for="unit_price_cents" class="form-label">Unit price (excl. VAT)</label>
                        <x-money-input name="unit_price_cents" min="0.01" :required="true" />
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-4">Issue debit note</button>
            </form>
        </div>
    </div>
@endif
@endsection

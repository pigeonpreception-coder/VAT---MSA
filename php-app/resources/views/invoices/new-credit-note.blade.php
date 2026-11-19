@extends('layouts.app')

@section('title', 'New Credit Note')

@php
    // Currency-code prefix, matching invoices/show.blade.php: an invoice's
    // currency is per-document, not always the viewer's own tenant currency.
    $fmt = fn (int $cents) => trim(($selected->currency ?? $tenantCurrencyCode).' '.number_format($cents / 100, 2));
    $todayIso = now()->toDateString();
@endphp

@section('content')
<x-page-header title="New credit note" :back="route('invoices.index')" back-label="All invoices"
    subtitle="Reduce or reverse a certified invoice you issued. The supplier and customer are copied from the original invoice." />

<x-form-errors />

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">1. Choose the original invoice</div>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('new-registration.credit-note') }}" class="row g-2 align-items-end">
            <div class="col-lg-8">
                <label for="invoice_id" class="form-label">Original invoice</label>
                <select id="invoice_id" name="invoice_id" class="form-select" onchange="this.form.submit()">
                    <option value="" @selected(! $selected)>Select an invoice to credit</option>
                    @foreach ($originals as $invoice)
                        <option value="{{ $invoice->id }}" @selected($selected && $selected->id === $invoice->id)>
                            {{ $invoice->invoice_number }} &middot; {{ $invoice->customer_name }} &middot; {{ $invoice->issue_date->format('d M Y') }} &middot; {{ $invoice->currency }} {{ number_format($invoice->total_cents / 100, 2) }}
                        </option>
                    @endforeach
                </select>
                @if ($originals->isEmpty())
                    <div class="form-text">You have no certified invoices that can be credited. Only invoices your organisation issued are listed.</div>
                @endif
            </div>
        </form>
    </div>
</div>

@if ($selected)
    <div class="row row-cols-1 row-cols-sm-3 g-3 mb-3">
        <div class="col"><x-stat-tile label="Customer" :value="$selected->customer_name" /></div>
        <div class="col"><x-stat-tile label="Original total" :value="$selected->currency.' '.number_format($selected->total_cents / 100, 2)" /></div>
        <div class="col">
            <x-stat-tile label="Still available to credit" :value="$selected->currency.' '.number_format($remainingCents / 100, 2)"
                :hint="$remainingCents <= 0 ? 'This invoice has been fully credited' : null" :tone="$remainingCents <= 0 ? 'danger' : null" />
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">2. Credit note details</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('new-registration.credit-note.store') }}">
                @csrf
                <x-idempotency-key />
                <input type="hidden" name="invoice_id" value="{{ $selected->id }}">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="reason_code" class="form-label">Reason code</label>
                        <input type="text" class="form-control text-uppercase" id="reason_code" name="reason_code" list="credit-reason-codes" required maxlength="40" placeholder="e.g. PRICING_ERROR" value="{{ old('reason_code') }}">
                        @include('invoices.partials.reason-codes', ['id' => 'credit-reason-codes', 'codes' => ['PRICING_ERROR', 'RETURNED_GOODS', 'DISCOUNT', 'DUPLICATE_INVOICE', 'CANCELLED_SERVICE']])
                    </div>
                    <div class="col-md-4">
                        <label for="issue_date" class="form-label">Issue date</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date" required min="{{ $selected->issue_date->toDateString() }}" value="{{ old('issue_date', $todayIso) }}">
                    </div>
                    <div class="col-12">
                        <label for="reason" class="form-label">Reason</label>
                        <textarea class="form-control" id="reason" name="reason" required minlength="5" maxlength="1000" rows="2" placeholder="Explain why the invoice is being credited">{{ old('reason') }}</textarea>
                    </div>
                </div>

                <label class="form-label">Quantities to credit</label>
                <div class="table-responsive border rounded mb-2">
                    <table class="table table-sm align-middle">
                        <caption class="visually-hidden">Original invoice lines with an editable quantity to credit against each</caption>
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Original qty</th>
                                <th scope="col" class="text-end">Unit price</th>
                                <th scope="col">VAT</th>
                                <th scope="col" class="quantity-col">Quantity to credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $line)
                                <tr>
                                    <td class="text-muted">{{ $line->line_number }}</td>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-end text-nowrap">{{ $line->quantity }} {{ $line->unit_code }}</td>
                                    <td class="text-end text-nowrap">{{ $fmt($line->unit_price_cents) }}</td>
                                    <td><x-status-badge :value="$line->tax_category" type="status" /></td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="form-control form-control-sm" name="credit_quantity[{{ $line->id }}]" aria-label="Quantity to credit for line {{ $line->line_number }}" placeholder="0" value="{{ old('credit_quantity.'.$line->id, '0') }}">
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">This invoice has no lines.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="form-text mb-4">Enter a quantity for each line you want to reverse and leave the others at 0. VAT is reduced automatically.</div>

                <button type="submit" class="btn btn-primary">Issue credit note</button>
            </form>
        </div>
    </div>
@endif
@endsection

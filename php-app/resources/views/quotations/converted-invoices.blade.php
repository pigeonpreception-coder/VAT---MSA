@extends('layouts.app')

@section('title', 'Converted Quotations into Invoices')

@php
    $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Commercial domain</div>
        <h1 class="h3 mb-1">Converted Quotations into Invoices</h1>
        <p class="text-muted mb-0">Every converted quotation with its real certified invoice, and every credit or debit note ever raised against that invoice, joined into one list.</p>
    </div>
    <a href="{{ route('quotations.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to quotations</a>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Cross-reference</div>
        <div class="text-muted small">{{ number_format(count($rows)) }} converted quotation{{ count($rows) === 1 ? '' : 's' }}</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Converted quotations with their invoice and any credit or debit notes</caption>
            <thead>
                <tr>
                    <th scope="col">Quotation</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Invoice</th>
                    <th scope="col" class="text-end">Invoice total</th>
                    <th scope="col">Credit / debit notes</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['quotation_number'] }}</strong>
                            <div class="text-muted small">{{ $row['currency'] }} {{ number_format($row['quotation_total_cents'] / 100, 2) }} quoted</div>
                        </td>
                        <td>{{ $row['customer_name'] ?? '—' }}</td>
                        <td>
                            @if ($row['invoice'])
                                <a href="{{ route('invoices.show', $row['invoice']['id']) }}">{{ $row['invoice']['invoice_number'] }}</a>
                                <div class="text-muted small">{{ $row['invoice']['issue_date'] }} &middot; <x-status-badge :value="$row['invoice']['status']" type="status" /></div>
                            @else
                                <span class="text-danger small">Invoice not found</span>
                            @endif
                        </td>
                        <td class="text-end">{{ $row['invoice'] ? $fmt($row['invoice']['total_cents']) : '—' }}</td>
                        <td>
                            @forelse ($row['corrections'] as $correction)
                                <div class="mb-1">
                                    <x-status-badge :value="$correction['correction_type']" type="status" />
                                    <strong>{{ $correction['invoice_number'] ?? '—' }}</strong>
                                    <span class="text-muted small">{{ $correction['issue_date'] }} &middot; {{ $fmt($correction['total_cents']) }}</span>
                                    <div class="text-muted small">{{ $correction['reason'] }}</div>
                                </div>
                            @empty
                                <span class="text-muted small">None</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No quotations have been converted to an invoice yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

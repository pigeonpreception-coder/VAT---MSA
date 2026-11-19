@extends('layouts.app')

@section('title', 'Local invoices')

@php
    $date = fn ($iso) => \Illuminate\Support\Carbon::parse($iso)->format('d M Y');
@endphp

@section('content')
<x-page-header title="Local invoices"
    :subtitle="'Domestic invoices you issued or received — sent from your own point-of-sale system or recorded in VAT-MSA\'s POS. Their match status feeds '.$tenantAuthorityShortName.'\'s VAT audit and refund reporting.'" />

<x-form-errors />
@if ($newCredential && $newCredential['client_secret'])
    <div class="alert alert-warning" role="alert">
        <i class="bi bi-key-fill" aria-hidden="true"></i>
        <div class="min-w-0">
            <div class="fw-semibold">Copy this secret now — it won't be shown again.</div>
            <div class="mt-2"><span class="small">Client key</span><div class="font-monospace text-break">{{ $newCredential['client_key'] }}</div></div>
            <div class="mt-2"><span class="small">Client secret</span><div class="font-monospace text-break">{{ $newCredential['client_secret'] }}</div></div>
            <div class="small mt-2">Configure your POS to send <code>Authorization: Bearer {{ $newCredential['client_key'] }}.{{ $newCredential['client_secret'] }}</code> with <code>POST /api/pos/v1/invoices</code>.</div>
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-header pb-0 border-bottom-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="issued-tab" data-bs-toggle="tab" data-bs-target="#issued-pane" type="button" role="tab" aria-controls="issued-pane" aria-selected="true">
                    Issued <span class="badge text-bg-light ms-1">{{ count($issued) }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="received-tab" data-bs-toggle="tab" data-bs-target="#received-pane" type="button" role="tab" aria-controls="received-pane" aria-selected="false">
                    Received <span class="badge text-bg-light ms-1">{{ count($received) }}</span>
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="issued-pane" role="tabpanel" aria-labelledby="issued-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Local invoices issued by this organisation (you are the supplier)</caption>
                    <thead>
                        <tr><th scope="col">Invoice</th><th scope="col">Customer</th><th scope="col">Date</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th><th scope="col">Source</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($issued as $invoice)
                            <tr>
                                <td><span class="fw-semibold">{{ $invoice['invoiceNumber'] }}</span><div class="text-muted small">@humanize($invoice['documentType'])</div></td>
                                <td>{{ $invoice['customerName'] }}</td>
                                <td class="text-nowrap">{{ $date($invoice['issueDate']) }}</td>
                                <td class="text-end text-nowrap">{{ $invoice['currency'] }} {{ number_format($invoice['totalCents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                                <td>{{ $invoice['sourceLabel'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No invoices issued yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="received-pane" role="tabpanel" aria-labelledby="received-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Local invoices received by this organisation (you are the customer)</caption>
                    <thead>
                        <tr><th scope="col">Invoice</th><th scope="col">Supplier</th><th scope="col">Date</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th><th scope="col">Source</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($received as $invoice)
                            <tr>
                                <td><span class="fw-semibold">{{ $invoice['invoiceNumber'] }}</span><div class="text-muted small">@humanize($invoice['documentType'])</div></td>
                                <td>{{ $invoice['supplierName'] }}</td>
                                <td class="text-nowrap">{{ $date($invoice['issueDate']) }}</td>
                                <td class="text-end text-nowrap">{{ $invoice['currency'] }} {{ number_format($invoice['totalCents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                                <td>{{ $invoice['sourceLabel'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No invoices received yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if ($canManageIntegrations)
    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">Point-of-sale connections</div>
            <div class="text-muted small">Issue a credential so your own POS system can send invoices here as they happen</div>
        </div>
        <div class="card-body border-bottom">
            <form method="POST" action="{{ route('invoice-management.local.credentials.store') }}" class="row g-2 align-items-end">
                @csrf
                <x-idempotency-key/>
                <div class="col-md-6">
                    <label for="credential-name" class="form-label">Connection name</label>
                    <input type="text" id="credential-name" name="name" class="form-control" placeholder="e.g. Front counter till" required maxlength="100" value="{{ old('name') }}">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key" aria-hidden="true"></i> Issue credential</button>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <caption class="visually-hidden">Private POS API credentials issued for this organisation</caption>
                <thead>
                    <tr><th scope="col">Name</th><th scope="col">Client key</th><th scope="col">Status</th><th scope="col">Issued</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    @forelse ($apiClients as $client)
                        <tr>
                            <td class="fw-semibold">{{ $client->name }}</td>
                            <td class="font-monospace small">{{ $client->client_key }}</td>
                            <td><x-status-badge :value="$client->status" type="status" /></td>
                            <td class="text-nowrap">{{ $client->created_at?->format('d M Y, H:i') }}</td>
                            <td class="text-end">
                                @if ($client->status === 'ACTIVE')
                                    <form method="POST" action="{{ route('invoice-management.local.credentials.revoke', $client->id) }}" onsubmit="return confirm('Revoke this credential? The POS system using it will stop working immediately.');">
                                        @csrf
                                        <x-idempotency-key/>
                                        <input type="hidden" name="reason" value="Revoked from Local Invoices">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No POS credentials issued yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

@extends('layouts.app')

@section('title', 'Local invoices')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Invoice Management</div>
    <h1 class="h3 mb-1">Local invoices</h1>
    <p class="text-muted mb-0">Domestic invoices issued or received in real time -- whether pushed by a taxpayer's own private Point-of-Sale system through a real API credential, or processed directly through VAT-MSA's own built-in POS module -- with the same cross-party match status behind NamRA's real-time VAT audit and refund reporting.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-warning" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
@if ($newCredential && $newCredential['client_secret'])
    <div class="alert alert-warning" role="alert">
        <div class="fw-semibold">Copy this secret now -- it will not be shown again.</div>
        <div class="mt-2"><span class="text-muted small">Client key</span><div class="font-monospace">{{ $newCredential['client_key'] }}</div></div>
        <div class="mt-2"><span class="text-muted small">Client secret</span><div class="font-monospace">{{ $newCredential['client_secret'] }}</div></div>
        <div class="text-muted small mt-2">Configure the POS system with header: <code>Authorization: Bearer {{ $newCredential['client_key'] }}.{{ $newCredential['client_secret'] }}</code> against <code>POST /api/pos/v1/invoices</code>.</div>
    </div>
@endif

@if ($canManageIntegrations)
<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Private POS API credentials</div>
        <div class="text-muted small">Issue a real credential so your own Point-of-Sale system can push invoices here in real time, without going through VAT-MSA's own POS module.</div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('invoice-management.local.credentials.store') }}" class="row g-2 align-items-end mb-3">
            @csrf
            <x-idempotency-key/>
            <div class="col-md-6">
                <label for="credential-name" class="form-label">Credential name</label>
                <input type="text" id="credential-name" name="name" class="form-control" placeholder="e.g. Front counter till" required maxlength="100" value="{{ old('name') }}">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Issue credential</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Private POS API credentials issued for this organisation</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Client key</th>
                        <th scope="col">Status</th>
                        <th scope="col">Issued</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($apiClients as $client)
                        <tr>
                            <td>{{ $client->name }}</td>
                            <td class="font-monospace small">{{ $client->client_key }}</td>
                            <td><x-status-badge :value="$client->status" type="status" /></td>
                            <td>{{ $client->created_at?->format('d M Y, H:i') }}</td>
                            <td>
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
                        <tr><td colspan="5" class="text-center text-muted py-3">No POS API credentials issued yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Issued (you are the supplier)</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Local invoices issued by this organisation</caption>
            <thead>
                <tr>
                    <th scope="col">Invoice</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Date</th>
                    <th scope="col" class="text-end">Total</th>
                    <th scope="col">Status</th>
                    <th scope="col">Source</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($issued as $invoice)
                    <tr>
                        <td><strong>{{ $invoice['invoiceNumber'] }}</strong><div class="text-muted small">{{ str_replace('_', ' ', $invoice['documentType']) }}</div></td>
                        <td>{{ $invoice['customerName'] }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($invoice['issueDate'])->format('d M Y') }}</td>
                        <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['totalCents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                        <td>{{ $invoice['sourceLabel'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No invoices issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Received (you are the customer)</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Local invoices received by this organisation</caption>
            <thead>
                <tr>
                    <th scope="col">Invoice</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Date</th>
                    <th scope="col" class="text-end">Total</th>
                    <th scope="col">Status</th>
                    <th scope="col">Source</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($received as $invoice)
                    <tr>
                        <td><strong>{{ $invoice['invoiceNumber'] }}</strong><div class="text-muted small">{{ str_replace('_', ' ', $invoice['documentType']) }}</div></td>
                        <td>{{ $invoice['supplierName'] }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($invoice['issueDate'])->format('d M Y') }}</td>
                        <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['totalCents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                        <td>{{ $invoice['sourceLabel'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No invoices received yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

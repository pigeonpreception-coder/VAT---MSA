@extends('layouts.app')

@section('title', $invoice['invoiceNumber'])

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? $invoice['currency']).' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Certified fiscal evidence</div>
        <h1 class="h3 mb-1">{{ $invoice['invoiceNumber'] }}</h1>
        <p class="text-muted mb-0">{{ $invoice['supplierName'] }} to {{ $invoice['customerName'] }}</p>
    </div>
    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary align-self-center">&larr; Back to invoices</a>
</div>

@if ($justCertified)
    <div class="alert alert-success" role="alert">
        <strong>Invoice certified successfully.</strong> The fiscal document, certificate, VAT transaction, ledger entries and audit evidence were committed as one controlled operation.
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Document record</div>
                    <div class="text-muted small">Canonical invoice and processing outcome</div>
                </div>
                <x-status-badge :value="$invoice['status']" type="status" />
            </div>
            <dl class="card-body row row-cols-1 row-cols-sm-2 g-3 mb-0">
                <div class="col"><dt class="text-muted small">Issue date</dt><dd class="mb-0 fw-semibold">{{ $date($invoice['issueDate']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Document type</dt><dd class="mb-0 fw-semibold">{{ $titleCase($invoice['documentType']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Net value</dt><dd class="mb-0 fw-semibold">{{ $money($invoice['lineNetCents']) }}</dd></div>
                <div class="col"><dt class="text-muted small">VAT amount</dt><dd class="mb-0 fw-semibold">{{ $money($invoice['taxCents']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Supplier VAT</dt><dd class="mb-0 fw-semibold">{{ $invoice['supplierVatNumber'] }}</dd></div>
                <div class="col"><dt class="text-muted small">Customer VAT</dt><dd class="mb-0 fw-semibold">{{ $invoice['customerVatNumber'] ?? 'Not registered' }}</dd></div>
                <div class="col"><dt class="text-muted small">Risk classification</dt><dd class="mb-0"><x-status-badge :value="$invoice['riskLevel']" type="risk" /></dd></div>
                <div class="col"><dt class="text-muted small">Source system</dt><dd class="mb-0 fw-semibold">{{ $invoice['sourceSystem'] }}</dd></div>
                <div class="col"><dt class="text-muted small">Source document</dt><dd class="mb-0 fw-semibold font-monospace small">{{ $invoice['sourceDocumentId'] }}</dd></div>
                <div class="col"><dt class="text-muted small">Certified at</dt><dd class="mb-0 fw-semibold">{{ $dateTime($invoice['certifiedAt']) }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Certification receipt</div>
                    <div class="text-muted small">Pilot signing profile; production requires approved HSM keys</div>
                </div>
                <x-status-badge value="CERTIFIED" type="status" />
            </div>
            <dl class="card-body mb-0">
                <div class="mb-3"><dt class="text-muted small">Certificate ID</dt><dd class="mb-0 fw-semibold font-monospace small text-break">{{ $invoice['certificateId'] }}</dd></div>
                <div class="mb-3"><dt class="text-muted small">VAT transaction ID</dt><dd class="mb-0 fw-semibold font-monospace small text-break">{{ $invoice['transactionId'] }}</dd></div>
                <div class="mb-3"><dt class="text-muted small">Invoice SHA-256</dt><dd class="mb-0 fw-semibold font-monospace small text-break">{{ $invoice['payloadHash'] }}</dd></div>
                <div class="row row-cols-1 row-cols-sm-2 g-3">
                    <div class="col"><dt class="text-muted small">Signature profile</dt><dd class="mb-0 fw-semibold">{{ $invoice['signatureProfile'] ?: '—' }}</dd></div>
                    <div class="col"><dt class="text-muted small">Verification token</dt><dd class="mb-0 fw-semibold font-monospace small text-break">{{ $invoice['verificationToken'] }}</dd></div>
                </div>
            </dl>
        </div>
    </div>
</div>

@if ($invoice['correction'] || count($invoice['corrections']))
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">Correction lineage</div>
                <div class="text-muted small">Original and correction records remain independently certified and reproducible</div>
            </div>
            <x-status-badge value="LINKED" type="status" />
        </div>
        <div class="card-body">
            @if ($invoice['correction'])
                <div class="alert alert-info mb-0" role="status">
                    <strong>This {{ strtolower($titleCase($invoice['correction']['correctionType'])) }} corrects
                        <a class="font-monospace" href="{{ route('invoices.show', $invoice['correction']['originalInvoiceId']) }}">{{ $invoice['correction']['originalInvoiceNumber'] }}</a>.</strong>
                    <br>{{ $invoice['correction']['reasonCode'] ?? 'CORRECTION' }}: {{ $invoice['correction']['reason'] }}
                </div>
            @endif

            @if (count($invoice['corrections']))
                <div class="table-responsive {{ $invoice['correction'] ? 'mt-3' : '' }}">
                    <table class="table table-hover mb-0 align-middle">
                        <caption class="visually-hidden">Correction documents issued against this invoice</caption>
                        <thead>
                            <tr><th scope="col">Correction</th><th scope="col">Type</th><th scope="col">Reason</th><th scope="col" class="text-end">Value</th><th scope="col">Status</th><th scope="col">Created</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice['corrections'] as $correction)
                                <tr>
                                    <td><a href="{{ route('invoices.show', $correction['correctionInvoiceId']) }}"><strong>{{ $correction['correctionInvoiceNumber'] }}</strong></a></td>
                                    <td>{{ $titleCase($correction['correctionType']) }}</td>
                                    <td>{{ $correction['reasonCode'] ?? 'CORRECTION' }}<div class="text-muted small">{{ $correction['reason'] }}</div></td>
                                    <td class="text-end">{{ $money($correction['totalCents']) }}</td>
                                    <td><x-status-badge :value="$correction['status']" type="status" /></td>
                                    <td>{{ $dateTime($correction['createdAt']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Invoice lines</div>
        <div class="text-muted small">Calculation evidence retained with the canonical record</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Line items making up this invoice's net and VAT totals</caption>
            <thead>
                <tr><th scope="col">#</th><th scope="col">Description</th><th scope="col">Quantity</th><th scope="col" class="text-end">Unit price</th><th scope="col">VAT category</th><th scope="col" class="text-end">Rate</th><th scope="col" class="text-end">Net</th><th scope="col" class="text-end">VAT</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice['lines'] as $line)
                    <tr>
                        <td>{{ $line['lineNumber'] }}</td>
                        <td>{{ $line['description'] }}</td>
                        <td>{{ $line['quantity'] }} {{ $line['unitCode'] }}</td>
                        <td class="text-end">{{ $money($line['unitPriceCents']) }}</td>
                        <td>{{ $titleCase($line['taxCategory']) }}</td>
                        <td class="text-end">{{ number_format($line['taxRateBps'] / 100, 2) }}%</td>
                        <td class="text-end">{{ $money($line['netAmountCents']) }}</td>
                        <td class="text-end">{{ $money($line['taxAmountCents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">VAT sub-ledger postings</div>
        <div class="text-muted small">Seller output and eligible buyer input are linked by one transaction</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Ledger entries this invoice posted, by taxpayer and direction</caption>
            <thead>
                <tr><th scope="col">Taxpayer</th><th scope="col">Entry</th><th scope="col">Direction</th><th scope="col">VAT period</th><th scope="col" class="text-end">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice['ledgerEntries'] as $entry)
                    <tr>
                        <td>{{ $entry['taxpayerName'] }}</td>
                        <td>{{ $titleCase($entry['entryType']) }}</td>
                        <td>{{ $titleCase($entry['direction']) }}</td>
                        <td>{{ $entry['period'] }}</td>
                        <td class="text-end">{{ $money($entry['amountCents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

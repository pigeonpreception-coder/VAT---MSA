@extends('layouts.app')

@section('title', $invoice['invoiceNumber'])

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? $invoice['currency']).' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $total = $invoice['totalCents'] ?? ($invoice['lineNetCents'] + $invoice['taxCents']);
@endphp

@section('content')
<x-page-header :title="$invoice['invoiceNumber']" :back="route('invoices.index')" back-label="All invoices"
    :subtitle="$invoice['supplierName'].' to '.$invoice['customerName']">
    <x-slot:actions>
        <x-status-badge :value="$invoice['status']" type="status" class="fs-6" />
    </x-slot:actions>
</x-page-header>

@if ($justCertified)
    <div class="alert alert-success" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <div><strong>Invoice certified.</strong> The certificate, VAT transaction and ledger entries were recorded together.</div>
    </div>
@endif

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-3">
    <div class="col"><x-stat-tile label="Net value" :value="$money($invoice['lineNetCents'])" /></div>
    <div class="col"><x-stat-tile label="VAT" :value="$money($invoice['taxCents'])" /></div>
    <div class="col"><x-stat-tile label="Total" :value="$money($total)" /></div>
    <div class="col"><x-stat-tile label="Issue date" :value="$date($invoice['issueDate'])" :hint="\App\Support\Ui\Humanize::enum($invoice['documentType'])" /></div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Invoice details</div>
    </div>
    <dl class="card-body detail-grid mb-0">
        <div><dt>Supplier</dt><dd>{{ $invoice['supplierName'] }}<span class="d-block text-muted small">VAT {{ $invoice['supplierVatNumber'] }}</span></dd></div>
        <div><dt>Customer</dt><dd>{{ $invoice['customerName'] }}<span class="d-block text-muted small">{{ $invoice['customerVatNumber'] ? 'VAT '.$invoice['customerVatNumber'] : 'Not VAT registered' }}</span></dd></div>
        <div><dt>Risk</dt><dd><x-status-badge :value="$invoice['riskLevel']" type="risk" /></dd></div>
        <div><dt>Certified</dt><dd>{{ $dateTime($invoice['certifiedAt']) }}</dd></div>
        <div><dt>Source system</dt><dd>{{ $invoice['sourceSystem'] }}</dd></div>
        <div><dt>Source reference</dt><dd class="font-monospace text-break">{{ $invoice['sourceDocumentId'] }}</dd></div>
    </dl>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Line items</div>
        <div class="text-muted small">Each line and its VAT calculation</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Line items making up this invoice's net and VAT totals</caption>
            <thead>
                <tr><th scope="col">#</th><th scope="col">Description</th><th scope="col">Quantity</th><th scope="col" class="text-end">Unit price</th><th scope="col">VAT category</th><th scope="col" class="text-end">Rate</th><th scope="col" class="text-end">Net</th><th scope="col" class="text-end">VAT</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice['lines'] as $line)
                    <tr>
                        <td class="text-muted">{{ $line['lineNumber'] }}</td>
                        <td>{{ $line['description'] }}</td>
                        <td class="text-nowrap">{{ $line['quantity'] }} {{ $line['unitCode'] }}</td>
                        <td class="text-end text-nowrap">{{ $money($line['unitPriceCents']) }}</td>
                        <td>@humanize($line['taxCategory'])</td>
                        <td class="text-end">{{ number_format($line['taxRateBps'] / 100, 2) }}%</td>
                        <td class="text-end text-nowrap">{{ $money($line['netAmountCents']) }}</td>
                        <td class="text-end text-nowrap">{{ $money($line['taxAmountCents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if ($invoice['correction'] || count($invoice['corrections']))
    <div class="card mt-3">
        <div class="card-header">
            <div class="fw-semibold">Corrections</div>
            <div class="text-muted small">Credit and debit notes linked to this invoice — each is certified separately</div>
        </div>
        <div class="card-body">
            @if ($invoice['correction'])
                <div class="alert alert-info mb-0" role="status">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>
                    <div>
                        <strong>This @humanize($invoice['correction']['correctionType']) corrects
                            <a href="{{ route('invoices.show', $invoice['correction']['originalInvoiceId']) }}">{{ $invoice['correction']['originalInvoiceNumber'] }}</a>.</strong>
                        <div>@humanize($invoice['correction']['reasonCode'] ?? 'CORRECTION'): {{ $invoice['correction']['reason'] }}</div>
                    </div>
                </div>
            @endif

            @if (count($invoice['corrections']))
                <div class="table-responsive {{ $invoice['correction'] ? 'mt-3' : '' }}">
                    <table class="table table-hover align-middle">
                        <caption class="visually-hidden">Correction documents issued against this invoice</caption>
                        <thead>
                            <tr><th scope="col">Document</th><th scope="col">Type</th><th scope="col">Reason</th><th scope="col" class="text-end">Value</th><th scope="col">Status</th><th scope="col">Created</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice['corrections'] as $correction)
                                <tr>
                                    <td><a href="{{ route('invoices.show', $correction['correctionInvoiceId']) }}">{{ $correction['correctionInvoiceNumber'] }}</a></td>
                                    <td>@humanize($correction['correctionType'])</td>
                                    <td>@humanize($correction['reasonCode'] ?? 'CORRECTION')<div class="text-muted small">{{ $correction['reason'] }}</div></td>
                                    <td class="text-end text-nowrap">{{ $money($correction['totalCents']) }}</td>
                                    <td><x-status-badge :value="$correction['status']" type="status" /></td>
                                    <td class="text-nowrap">{{ $dateTime($correction['createdAt']) }}</td>
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
        <div class="fw-semibold">VAT postings</div>
        <div class="text-muted small">How this invoice was posted to each party's VAT account</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Ledger entries this invoice posted, by taxpayer and direction</caption>
            <thead>
                <tr><th scope="col">Taxpayer</th><th scope="col">Entry</th><th scope="col">Direction</th><th scope="col">VAT period</th><th scope="col" class="text-end">Amount</th></tr>
            </thead>
            <tbody>
                @forelse ($invoice['ledgerEntries'] as $entry)
                    <tr>
                        <td>{{ $entry['taxpayerName'] }}</td>
                        <td>@humanize($entry['entryType'])</td>
                        <td>@humanize($entry['direction'])</td>
                        <td>{{ $entry['period'] }}</td>
                        <td class="text-end text-nowrap">{{ $money($entry['amountCents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No VAT postings recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Certification details</div>
        <div class="text-muted small">Digital certificate and verification references</div>
    </div>
    <dl class="card-body detail-grid mb-0">
        <div><dt>Certificate ID</dt><dd class="font-monospace text-break">{{ $invoice['certificateId'] }}</dd></div>
        <div><dt>VAT transaction ID</dt><dd class="font-monospace text-break">{{ $invoice['transactionId'] }}</dd></div>
        <div><dt>Signature profile</dt><dd>{{ $invoice['signatureProfile'] ?: '—' }}</dd></div>
        <div><dt>Verification token</dt><dd class="font-monospace text-break">{{ $invoice['verificationToken'] }}</dd></div>
        <div class="detail-grid-wide"><dt>Invoice fingerprint (SHA-256)</dt><dd class="font-monospace text-break">{{ $invoice['payloadHash'] }}</dd></div>
    </dl>
</div>
@endsection

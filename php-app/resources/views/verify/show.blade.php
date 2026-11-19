@extends('layouts.app')

@section('title', 'Verify VAT certificate')

@php
    $money = fn (int $cents, string $currency) => trim($currency.' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $valid = $record['certificate_status'] === 'VALID';
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm mt-5">
                        <div class="card-body p-4 text-center {{ $valid ? 'bg-success-subtle' : 'bg-warning-subtle' }}">
                <div class="icon-circle {{ $valid ? '' : 'icon-circle-warning' }} mx-auto mb-3">
                    <i class="bi {{ $valid ? 'bi-patch-check' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>
                </div>
                <p class="small text-muted mb-1">VAT-MSA certificate check</p>
                <h1 class="h4 mb-2">{{ $valid ? 'Valid pilot certificate' : 'Certificate requires attention' }}</h1>
                <p class="small text-muted mb-0">Only the details needed to confirm the certificate are shown.</p>
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-5 col-sm-4 text-muted">Supplier</dt>
                    <dd class="col-7 col-sm-8 fw-semibold">{{ $record['supplier_name'] }}</dd>

                    <dt class="col-5 col-sm-4 text-muted">Invoice</dt>
                    <dd class="col-7 col-sm-8 fw-semibold">{{ \App\Support\Invoice\InvoiceNumberMask::mask($record['invoice_number']) }}</dd>

                    <dt class="col-5 col-sm-4 text-muted">Gross amount</dt>
                    <dd class="col-7 col-sm-8 fw-semibold">{{ $money((int) $record['total_cents'], $record['currency']) }}</dd>

                    <dt class="col-5 col-sm-4 text-muted">Status</dt>
                    <dd class="col-7 col-sm-8 fw-semibold">@humanize($record['certificate_status'])</dd>

                    <dt class="col-5 col-sm-4 text-muted">Certified at</dt>
                    <dd class="col-7 col-sm-8 fw-semibold">{{ $dateTime($record['issued_at']) }}</dd>

                    <dt class="col-5 col-sm-4 text-muted">Invoice fingerprint</dt>
                    <dd class="col-7 col-sm-8"><code class="small text-break">{{ $record['invoice_hash'] }}</code></dd>

                    @if ($record['is_correction'])
                        <dt class="col-5 col-sm-4 text-muted">Corrects invoice</dt>
                        <dd class="col-7 col-sm-8 fw-semibold">
                            {{ $record['corrects_invoice_number'] ? \App\Support\Invoice\InvoiceNumberMask::mask($record['corrects_invoice_number']) : '—' }}
                                                        (@humanize($record['correction_type']))
                        </dd>
                    @endif
                </dl>

                @if (count($record['corrections']) > 0)
                    <hr>
                    <h2 class="h6">Corrections issued against this invoice</h2>
                    <table class="table table-sm mb-0">
                        <thead>
                                                        <tr><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Invoice</th><th scope="col" class="text-end">Amount</th><th scope="col">Date</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($record['corrections'] as $correction)
                                <tr>
                                                                        <td>@humanize($correction['correction_type'])</td>
                                    <td>@humanize($correction['status'])</td>
                                    <td>{{ \App\Support\Invoice\InvoiceNumberMask::mask($correction['invoice_number']) }}</td>
                                    <td class="text-end">{{ $money((int) $correction['total_cents'], $record['currency']) }}</td>
                                    <td>{{ $dateTime($correction['created_at']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                                <div class="alert alert-warning mt-4 mb-0 small" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>This is a pilot certificate. Legally binding signatures need {{ $tenantAuthorityShortName }}'s approved signing profile and protected signing keys.</div>
                </div>
                <div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="{{ url('/') }}">Return to VAT-MSA</a></div>
            </div>
        </div>
    </div>
</div>
@endsection

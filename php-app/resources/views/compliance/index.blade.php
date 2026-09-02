@extends('layouts.app')

@section('title', 'Compliance and disputes')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? 'NAD').' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : 'Open';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $openObligations = collect($snapshot['obligations'])->reject(fn ($item) => in_array($item['status'], ['SATISFIED', 'CANCELLED'], true))->count();
    $activeDisputes = collect($snapshot['disputes'])->reject(fn ($item) => in_array($item['status'], ['DECIDED', 'WITHDRAWN'], true))->count();
    $unreadNotices = collect($snapshot['notifications'])->where('status', 'UNREAD')->count();
    $activeConsents = collect($snapshot['consents'])->where('status', 'ACTIVE')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Taxpayer governance</div>
    <h1 class="h3 mb-1">Obligations, disputes and secure communications</h1>
    <p class="text-muted mb-0">Taxpayer instructions, delegations, notices and dispute rights are recorded as governed evidence. VAT-MSA does not replace the statutory account maintained by ITAS.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Open obligations</span><span>O</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($openObligations) }}</div>
            <div class="small text-muted">Source-labelled obligation projections</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active disputes</span><span>D</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($activeDisputes) }}</div>
            <div class="small text-muted">Independent review rights preserved</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Unread notices</span><span>N</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($unreadNotices) }}</div>
            <div class="small text-muted">Secure portal notification queue</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active consents</span><span>C</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($activeConsents) }}</div>
            <div class="small text-muted">Purpose and validity are explicit</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Tax obligations</div>
                <div class="text-muted small">Authoritative source is always displayed</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Tax obligations by taxpayer, period and due date</caption>
                    <thead>
                        <tr><th scope="col">Taxpayer</th><th scope="col">Obligation</th><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Amount</th><th scope="col">Source</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['obligations'] as $item)
                            <tr>
                                <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</td>
                                <td>{{ $titleCase($item['obligation_type']) }}</td>
                                <td>{{ $item['period_code'] }}</td>
                                <td>{{ $item['due_date'] }}</td>
                                <td class="text-end">{{ $money($item['amount_cents'], $item['currency']) }}</td>
                                <td>{{ $item['source_system'] }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No obligations on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Dispute register</div>
                <div class="text-muted small">Filing is immutable; decisions append later</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Disputes filed against audit findings, VAT returns, refund decisions or obligations</caption>
                    <thead>
                        <tr><th scope="col">Dispute</th><th scope="col">Taxpayer</th><th scope="col">Resource</th><th scope="col">Amount</th><th scope="col">Filed</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['disputes'] as $item)
                            <tr>
                                <td><strong>{{ $item['dispute_number'] }}</strong></td>
                                <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</td>
                                <td>{{ $titleCase($item['disputed_resource_type']) }}<div class="text-muted small font-monospace">{{ $item['disputed_resource_id'] }}</div></td>
                                <td class="text-end">{{ $money($item['disputed_amount_cents'], $item['currency']) }}</td>
                                <td>{{ $dateTime($item['filed_at']) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No disputes on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Secure communications</div>
                <div class="text-muted small">Summaries only; protected content remains classified</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Secure communications and notices</caption>
                    <thead>
                        <tr><th scope="col">Channel</th><th scope="col">Direction</th><th scope="col">Subject</th><th scope="col">Related record</th><th scope="col">Status</th><th scope="col">Occurred</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['communications'] as $item)
                            <tr>
                                <td>{{ $item['channel'] }}</td>
                                <td>{{ $titleCase($item['direction']) }}</td>
                                <td><strong>{{ $item['subject'] }}</strong><div class="text-muted small">{{ $item['content_summary'] }}</div></td>
                                <td>{{ $item['related_resource_type'] ?? '—' }}<div class="text-muted small font-monospace">{{ $item['related_resource_id'] ?? '' }}</div></td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                                <td>{{ $dateTime($item['occurred_at']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No communications on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Consent and delegation</div>
                <div class="text-muted small">Purpose-bound access with expiry</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Taxpayer consent grants and delegations, with validity period and status</caption>
                    <thead>
                        <tr><th scope="col">Type</th><th scope="col">Purpose / scope</th><th scope="col">Valid from</th><th scope="col">Valid to</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['consents'] as $item)
                            <tr>
                                <td>Consent</td>
                                <td>{{ $item['purpose'] }}<div class="text-muted small font-monospace">{{ $item['data_categories'] }}</div></td>
                                <td>{{ $date($item['valid_from']) }}</td>
                                <td>{{ $date($item['valid_to']) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                        @endforelse
                        @forelse ($snapshot['delegations'] as $item)
                            <tr>
                                <td>Delegation</td>
                                <td class="font-monospace">{{ $item['scopes'] }}</td>
                                <td>{{ $date($item['valid_from']) }}</td>
                                <td>{{ $date($item['valid_to']) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                        @endforelse
                        @if (count($snapshot['consents']) === 0 && count($snapshot['delegations']) === 0)
                            <tr><td colspan="5" class="text-center text-muted py-4">No consents or delegations on record.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

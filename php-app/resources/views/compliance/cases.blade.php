@extends('layouts.app')

@section('title', 'Audit cases and risk')

@php
    $money = fn (int $cents, ?string $currency = null) => trim(($currency ?? 'NAD').' '.number_format($cents / 100, 2));
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $openCases = collect($snapshot['cases'])->reject(fn ($item) => $item['status'] === 'CLOSED')->count();
    $preliminaryFindings = collect($snapshot['findings'])->where('status', 'PRELIMINARY')->count();
    $criticalReview = collect($snapshot['risks'])->filter(fn ($item) => $item['severity'] === 'CRITICAL' && in_array($item['status'], ['OPEN', 'UNDER_REVIEW'], true))->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance operations</div>
    <h1 class="h3 mb-1">Evidence-led audit cases and advisory risk</h1>
    <p class="text-muted mb-0">Rules may prioritise human review, but cannot create an adverse tax decision. Officers must preserve evidence, explain findings and record a reviewable decision.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Open cases</span><span>C</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($openCases) }}</div>
            <div class="small text-muted">Controlled officer work queue</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Preliminary findings</span><span>F</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($preliminaryFindings) }}</div>
            <div class="small text-muted">Not final assessments</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Risk indicators</span><span>R</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['risks'])) }}</div>
            <div class="small text-muted">Explainable, advisory-only signals</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Critical review</span><span>!</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($criticalReview) }}</div>
            <div class="small {{ $criticalReview > 0 ? 'text-warning' : 'text-success' }}">Human review required</div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Audit case register</div>
        <div class="text-muted small">National compliance scope</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Audit cases, their type, risk tier and current status</caption>
            <thead>
                <tr><th scope="col">Case</th><th scope="col">Taxpayer</th><th scope="col">Type</th><th scope="col">Title</th><th scope="col">Risk</th><th scope="col">Status</th><th scope="col">Opened</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['cases'] as $item)
                    <tr>
                        <td><strong>{{ $item['case_number'] }}</strong><div class="text-muted small font-monospace">{{ $item['id'] }}</div></td>
                        <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}<div class="text-muted small font-monospace">{{ $item['vat_number'] ?? '' }}</div></td>
                        <td>{{ $titleCase($item['case_type']) }}</td>
                        <td>{{ $item['title'] }}<div class="text-muted small">{{ $item['opening_reason'] }}</div></td>
                        <td><x-status-badge :value="$item['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                        <td>{{ $dateTime($item['opened_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No audit cases yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Findings</div>
                <div class="text-muted small">Legal references remain empty until authority-confirmed</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Findings issued against audit cases</caption>
                    <thead>
                        <tr><th scope="col">Finding</th><th scope="col">Case</th><th scope="col">Amount</th><th scope="col">Legal reference</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['findings'] as $item)
                            <tr>
                                <td><strong>{{ $item['title'] }}</strong><div class="text-muted small">{{ $item['description'] }}</div></td>
                                <td>{{ $item['case_number'] ?? $item['audit_case_id'] }}</td>
                                <td class="text-end">{{ $money($item['amount_cents'], $item['currency']) }}</td>
                                <td>{{ $item['legal_reference'] ?? 'Awaiting authoritative mapping' }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No findings yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Advisory risk indicators</div>
                <div class="text-muted small">No automated adverse decision effect</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Risk indicators, their score, rationale and status</caption>
                    <thead>
                        <tr><th scope="col">Indicator</th><th scope="col">Subject</th><th scope="col">Score</th><th scope="col">Rationale</th><th scope="col">Effect</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['risks'] as $item)
                            <tr>
                                <td><strong>{{ $titleCase($item['indicator_code']) }}</strong><div>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</div></td>
                                <td>{{ $item['subject_type'] }}<div class="text-muted small font-monospace">{{ $item['subject_id'] }}</div></td>
                                <td>{{ number_format($item['score_bps'] / 100, 2) }}%</td>
                                <td>{{ $item['rationale'] }}</td>
                                <td><x-status-badge :value="$item['decision_effect']" type="status" /></td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No risk indicators yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

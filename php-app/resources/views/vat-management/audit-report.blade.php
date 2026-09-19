@extends('layouts.app')

@section('title', 'VAT Audit Report')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT Management</div>
    <h1 class="h3 mb-1">VAT Audit Report</h1>
    <p class="text-muted mb-0">A period's certified-invoice risk posture: risk-level breakdown, reconciliation exceptions raised, and any audit case opened.</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('vat-management.audit-report') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label for="period_id" class="form-label">VAT period</label>
                <select name="period_id" id="period_id" class="form-select" onchange="this.form.submit()">
                    @foreach ($periods as $period)
                        <option value="{{ $period->id }}" @selected($selectedPeriodId === $period->id)>
                            {{ $period->taxpayer?->legal_name }} &mdash; {{ $period->period_code }}
                            ({{ optional($period->period_start)->format('d M Y') }} to {{ optional($period->period_end)->format('d M Y') }})
                        </option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

@if (! $report)
    <div class="card"><div class="card-body text-center text-muted py-5">No VAT period is available to report on.</div></div>
@else
    @php
        $p = $report['period'];
        $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
    @endphp

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Taxpayer</div>
                    <div class="fw-semibold">{{ $p['legal_name'] }}</div>
                    <div class="text-muted small">{{ $p['vat_number'] }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Period</div>
                    <div class="fw-semibold">{{ $p['period_code'] }}</div>
                    <div class="text-muted small">{{ $p['period_start'] }} to {{ $p['period_end'] }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Period status</div>
                    <div><x-status-badge :value="$p['status']" type="status" /></div>
                </div>
            </div>
        </div>
    </div>

    @php $summary = $report['invoice_summary']; @endphp
    <div class="card mb-4">
        <div class="card-header"><strong>Certified invoices by risk level</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Certified invoices grouped by risk level</caption>
                <thead>
                    <tr>
                        <th scope="col">Risk level</th>
                        <th scope="col" class="text-end">Invoices</th>
                        <th scope="col" class="text-end">Total value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['by_risk_level'] as $row)
                        <tr>
                            <td><x-status-badge :value="$row['risk_level']" type="risk" /></td>
                            <td class="text-end">{{ $row['count'] }}</td>
                            <td class="text-end">{{ $fmt($row['total_cents']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="fw-semibold table-light">
                        <td>Total</td>
                        <td class="text-end">{{ $summary['total_count'] }}</td>
                        <td class="text-end">{{ $fmt($summary['total_cents']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            @foreach ($summary['by_status'] as $row)
                <span class="badge text-bg-light border me-2">{{ $row['status'] }}: {{ $row['count'] }}</span>
            @endforeach
            @if (empty($summary['by_status']))
                <span class="text-muted small">No certified invoices in this period.</span>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Reconciliation exceptions</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Reconciliation exceptions raised during this period</caption>
                <thead>
                    <tr>
                        <th scope="col">Invoice</th>
                        <th scope="col">Type</th>
                        <th scope="col">Severity</th>
                        <th scope="col">Status</th>
                        <th scope="col">Summary</th>
                        <th scope="col">Raised</th>
                        <th scope="col">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['exceptions'] as $exception)
                        <tr>
                            <td>{{ $exception['invoice_number'] }}</td>
                            <td>{{ $exception['exception_type'] }}</td>
                            <td><x-status-badge :value="$exception['severity']" type="risk" /></td>
                            <td><x-status-badge :value="$exception['status']" type="status" /></td>
                            <td>{{ $exception['summary'] }}</td>
                            <td>{{ $exception['created_at'] }}</td>
                            <td>{{ $exception['resolved_at'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No reconciliation exceptions raised in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Audit cases opened this period</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Audit cases opened against this taxpayer during this period</caption>
                <thead>
                    <tr>
                        <th scope="col">Case</th>
                        <th scope="col">Type</th>
                        <th scope="col">Title</th>
                        <th scope="col">Risk tier</th>
                        <th scope="col">Status</th>
                        <th scope="col">Opened</th>
                        <th scope="col">Closed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['audit_cases'] as $case)
                        <tr>
                            <td>{{ $case['case_number'] }}</td>
                            <td>{{ $case['case_type'] }}</td>
                            <td>{{ $case['title'] }}</td>
                            <td><x-status-badge :value="$case['risk_tier']" type="risk" /></td>
                            <td><x-status-badge :value="$case['status']" type="status" /></td>
                            <td>{{ $case['opened_at'] }}</td>
                            <td>{{ $case['closed_at'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No audit cases opened against this taxpayer during this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

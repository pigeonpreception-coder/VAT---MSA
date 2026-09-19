@extends('layouts.app')

@section('title', 'Risk indicators summary report')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Compliance domain &middot; NamRA-restricted</div>
        <h1 class="h3 mb-1">Risk indicators summary report</h1>
        <p class="text-muted mb-0">A national, all-time rollup of the live risk-indicator register: severity and status posture, which rule fired, and the taxpayers most flagged.</p>
    </div>
    <a href="{{ route('risk-indicators.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to register</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>By severity</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Risk indicators grouped by severity</caption>
                    <thead>
                        <tr><th scope="col">Severity</th><th scope="col" class="text-end">Count</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['by_severity'] as $row)
                            <tr>
                                <td><x-status-badge :value="$row['severity']" type="risk" /></td>
                                <td class="text-end">{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="fw-semibold table-light">
                            <td>Total</td>
                            <td class="text-end">{{ $summary['total_count'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>By status</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Risk indicators grouped by review status</caption>
                    <thead>
                        <tr><th scope="col">Status</th><th scope="col" class="text-end">Count</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['by_status'] as $row)
                            <tr>
                                <td><x-status-badge :value="$row['status']" type="indicator" /></td>
                                <td class="text-end">{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>By rule (indicator type)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Risk indicators grouped by which rule fired</caption>
            <thead>
                <tr><th scope="col">Rule</th><th scope="col" class="text-end">Count</th></tr>
            </thead>
            <tbody>
                @foreach ($summary['by_indicator_code'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-end">{{ $row['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Most-flagged taxpayers</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Taxpayers with the most risk indicators, highest first</caption>
            <thead>
                <tr>
                    <th scope="col">Taxpayer</th>
                    <th scope="col" class="text-end">Indicators</th>
                    <th scope="col" class="text-end">Critical</th>
                    <th scope="col" class="text-end">Open / under review</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($summary['top_taxpayers'] as $row)
                    <tr>
                        <td>
                            {{ $row['legal_name'] ?? '—' }}
                            <div class="text-muted small">{{ $row['vat_number'] }}</div>
                        </td>
                        <td class="text-end">{{ $row['indicator_count'] }}</td>
                        <td class="text-end">{{ $row['critical_count'] }}</td>
                        <td class="text-end">{{ $row['open_count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No risk indicators have been raised yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

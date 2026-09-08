@extends('layouts.app')

@section('title', 'Risk indicators')

@php
    $page = intdiv($offset, max($limit, 1)) + 1;
    $lastPage = (int) ceil(max($totalCount, 1) / max($limit, 1));
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain &middot; NamRA-restricted</div>
    <h1 class="h3 mb-1">Risk indicators</h1>
    <p class="text-muted mb-0">Advisory-only signals from a small, fixed, code-versioned rule catalogue -- never a black-box score, and never auto-escalated to a case without an authorised officer's own decision.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@can('permission', 'risk:review')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Evaluate a taxpayer</h2>
            <p class="text-muted small">Runs the current rule catalogue against a taxpayer's live evidence (invoice risk levels, reconciliation exceptions, overdue obligations) and raises or refreshes any indicators that fire.</p>
            @if ($errors->has('vat_number'))
                <div class="alert alert-danger py-2" role="alert">{{ $errors->first('vat_number') }}</div>
            @endif
            <form method="POST" action="{{ route('risk-indicators.evaluation.store') }}" class="row g-2">
                @csrf
                <div class="col-md-4">
                    <label for="vat_number" class="visually-hidden">VAT number</label>
                    <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control" placeholder="VAT number, e.g. VAT-DEMO-0001" required>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Evaluate</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('risk-indicators.index') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($filters['status'] === '')>All statuses</option>
                    <option value="OPEN" @selected($filters['status'] === 'OPEN')>Open</option>
                    <option value="UNDER_REVIEW" @selected($filters['status'] === 'UNDER_REVIEW')>Under review</option>
                    <option value="ESCALATED_TO_CASE" @selected($filters['status'] === 'ESCALATED_TO_CASE')>Escalated to case</option>
                    <option value="DISMISSED" @selected($filters['status'] === 'DISMISSED')>Dismissed</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="severity" class="form-label small mb-0">Severity</label>
                <select id="severity" name="severity" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($filters['severity'] === '')>All severities</option>
                    <option value="LOW" @selected($filters['severity'] === 'LOW')>Low</option>
                    <option value="MEDIUM" @selected($filters['severity'] === 'MEDIUM')>Medium</option>
                    <option value="HIGH" @selected($filters['severity'] === 'HIGH')>High</option>
                    <option value="CRITICAL" @selected($filters['severity'] === 'CRITICAL')>Critical</option>
                </select>
            </div>
            <div class="col-md-4 text-md-end small text-muted">
                {{ $totalCount }} indicator{{ $totalCount === 1 ? '' : 's' }}
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Risk indicators, filterable by status and severity</caption>
            <thead>
                <tr>
                    <th scope="col">Indicator</th>
                    <th scope="col">Taxpayer</th>
                    <th scope="col">Severity</th>
                    <th scope="col" class="text-end">Score</th>
                    <th scope="col">Status</th>
                    <th scope="col">Detected</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($indicators as $indicator)
                    <tr>
                        <td><a href="{{ route('risk-indicators.show', $indicator['id']) }}"><strong>{{ ucwords(strtolower(str_replace('_', ' ', $indicator['indicator_code']))) }}</strong></a></td>
                        <td>
                            {{ $indicator['legal_name'] ?? '—' }}
                            <div class="text-muted small">{{ $indicator['vat_number'] ?? '' }}</div>
                        </td>
                        <td><x-status-badge :value="$indicator['severity']" type="risk" /></td>
                        <td class="text-end">{{ number_format($indicator['score_bps'] / 100, 1) }}%</td>
                        <td><x-status-badge :value="$indicator['status']" type="indicator" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($indicator['detected_at'])->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No risk indicators match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($lastPage > 1)
        <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
            <span class="text-muted small">Page {{ $page }} of {{ $lastPage }}</span>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary {{ $offset <= 0 ? 'disabled' : '' }}" href="{{ route('risk-indicators.index', array_filter(['status' => $filters['status'], 'severity' => $filters['severity'], 'offset' => max(0, $offset - $limit)])) }}">&larr; Previous</a>
                <a class="btn btn-outline-secondary {{ $offset + $limit >= $totalCount ? 'disabled' : '' }}" href="{{ route('risk-indicators.index', array_filter(['status' => $filters['status'], 'severity' => $filters['severity'], 'offset' => $offset + $limit])) }}">Next &rarr;</a>
            </div>
        </div>
    @endif
</div>
@endsection

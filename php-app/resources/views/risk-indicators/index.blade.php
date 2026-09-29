@extends('layouts.app')

@section('title', 'Risk indicators')

@php
    $page = intdiv($offset, max($limit, 1)) + 1;
    $lastPage = (int) ceil(max($totalCount, 1) / max($limit, 1));
    $topTaxpayerPreviewLimit = 5;
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Compliance domain &middot; {{ $tenantAuthorityShortName }}-restricted</div>
        <h1 class="h3 mb-1">Risk indicators</h1>
        <p class="text-muted mb-0">Advisory-only signals from a small, fixed, code-versioned rule catalogue -- never a black-box score, and never auto-escalated to a case without an authorised officer's own decision.</p>
    </div>
    <a href="{{ route('risk-indicators.report') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">View summary report</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Total indicators</span><span>N</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['total']) }}</div>
                <div class="small text-muted">Across the full register</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Needs review</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['needs_review']) }}</div>
                <div class="small text-muted">Open or under review</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Critical severity</span><span>C</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['critical']) }}</div>
                <div class="small {{ $metrics['critical'] > 0 ? 'text-danger' : 'text-muted' }}">Prioritised for officer attention</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Escalated to case</span><span>&rarr;</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['escalated']) }}</div>
                <div class="small text-muted">Formal audit cases opened</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
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
                            <x-idempotency-key />
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
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">Indicators by severity</div>
            <div class="card-body">
                <dl class="row row-cols-1 mb-0">
                    @foreach ($bySeverity as $row)
                        <div class="col mb-2 d-flex justify-content-between align-items-center">
                            <x-status-badge :value="$row['severity']" type="risk" />
                            <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Most-flagged taxpayers</span>
                <span class="badge text-bg-light border">{{ count($topTaxpayers) }}</span>
            </div>
            <div class="card-body">
                @forelse ($topTaxpayers as $index => $row)
                    <div class="mb-3 pb-3 border-bottom top-taxpayer-item" @if ($index >= $topTaxpayerPreviewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $row['legal_name'] ?? 'Unknown taxpayer' }}</strong>
                            <span class="fw-semibold">{{ $row['indicator_count'] }}</span>
                        </div>
                        <div class="text-muted small mt-1">{{ $row['vat_number'] ?? '' }}</div>
                        <div class="text-muted small mt-1">
                            @if ($row['critical_count'] > 0)
                                <span class="text-danger">{{ $row['critical_count'] }} critical</span> &middot;
                            @endif
                            {{ $row['open_count'] }} open/under review
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No risk indicators have been raised yet.</p>
                @endforelse
                @if (count($topTaxpayers) > $topTaxpayerPreviewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="top-taxpayer-item" data-show-more-count="{{ count($topTaxpayers) - $topTaxpayerPreviewLimit }}">
                        Show {{ count($topTaxpayers) - $topTaxpayerPreviewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-show-more-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.dataset.expanded === '1';
                var items = document.querySelectorAll('.' + button.dataset.showMoreTarget);
                items.forEach(function (item, index) {
                    if (index >= 5) { item.hidden = expanded; }
                });
                button.dataset.expanded = expanded ? '0' : '1';
                button.textContent = expanded
                    ? 'Show ' + button.dataset.showMoreCount + ' more'
                    : 'Show fewer';
            });
        });
    })();
</script>
@endpush

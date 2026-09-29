@extends('layouts.app')

@section('title', 'VAT Audit Report')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT Management</div>
    <h1 class="h3 mb-1">VAT Audit Report</h1>
    <p class="text-muted mb-0">A period's certified-invoice risk posture: risk-level breakdown, reconciliation exceptions raised, and any audit case opened.</p>
</div>

@if (! $report)
    <div class="card"><div class="card-body text-center text-muted py-5">No VAT period is available to report on.</div></div>
@else
    @php
        $p = $report['period'];
        $summary = $report['invoice_summary'];
        $exceptions = $report['exceptions'];
        $auditCases = $report['audit_cases'];
        $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
        $highCriticalCount = collect($summary['by_risk_level'])->whereIn('risk_level', ['HIGH', 'CRITICAL'])->sum('count');
        $listPreviewLimit = 5;
    @endphp

    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Certified invoices</span><span>N</span></div>
                    <div class="fs-2 fw-semibold">{{ number_format($summary['total_count']) }}</div>
                    <div class="small text-muted">All risk levels combined</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Certified value</span><span>N$</span></div>
                    <div class="fs-2 fw-semibold">{{ $fmt($summary['total_cents']) }}</div>
                    <div class="small text-muted">Total certified output</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-muted small text-uppercase"><span>High + critical risk</span><span>!</span></div>
                    <div class="fs-2 fw-semibold">{{ number_format($highCriticalCount) }}</div>
                    <div class="small {{ $highCriticalCount > 0 ? 'text-danger' : 'text-muted' }}">Elevated review needed</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Exceptions raised</span><span>X</span></div>
                    <div class="fs-2 fw-semibold">{{ number_format(count($exceptions)) }}</div>
                    <div class="small {{ count($exceptions) > 0 ? 'text-warning' : 'text-muted' }}">Reconciliation flags this period</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-7">
                            <strong>Certified invoices by risk level</strong>
                        </div>
                        <div class="col-md-5">
                            <form method="GET" action="{{ route('vat-management.audit-report') }}">
                                <label for="period_id" class="visually-hidden">VAT period</label>
                                <select name="period_id" id="period_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                    @foreach ($periods as $period)
                                        <option value="{{ $period->id }}" @selected($selectedPeriodId === $period->id)>
                                            {{ $period->taxpayer?->legal_name }} &mdash; {{ $period->period_code }}
                                        </option>
                                    @endforeach
                                </select>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
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
        </div>

        <div class="col-lg-4 d-flex flex-column gap-3">
            <div class="card">
                <div class="card-header fw-semibold">Period details</div>
                <div class="card-body">
                    <dl class="row row-cols-1 mb-0">
                        <div class="col mb-2">
                            <dt class="text-muted small">Taxpayer</dt>
                            <dd class="mb-0">{{ $p['legal_name'] }}</dd>
                            <div class="text-muted small">{{ $p['vat_number'] }}</div>
                        </div>
                        <div class="col mb-2">
                            <dt class="text-muted small">Period</dt>
                            <dd class="mb-0">{{ $p['period_code'] }}</dd>
                            <div class="text-muted small">{{ $p['period_start'] }} to {{ $p['period_end'] }}</div>
                        </div>
                        <div class="col mb-0">
                            <dt class="text-muted small d-inline">Status:</dt>
                            <dd class="d-inline mb-0"><x-status-badge :value="$p['status']" type="status" /></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Reconciliation exceptions</span>
                    <span class="badge text-bg-light border">{{ count($exceptions) }}</span>
                </div>
                <div class="card-body">
                    @forelse ($exceptions as $index => $exception)
                        <div class="mb-3 pb-3 border-bottom exception-item" @if ($index >= $listPreviewLimit) hidden @endif>
                            <div class="d-flex justify-content-between align-items-start">
                                <strong>{{ $exception['invoice_number'] }}</strong>
                                <x-status-badge :value="$exception['severity']" type="risk" />
                            </div>
                            <div class="text-muted small">{{ $exception['exception_type'] }} &middot; <x-status-badge :value="$exception['status']" type="status" /></div>
                            <div class="small mt-1">{{ $exception['summary'] }}</div>
                            <div class="text-muted small mt-1">
                                Raised {{ $exception['created_at'] }}
                                @if ($exception['resolved_at'])
                                    &middot; Resolved {{ $exception['resolved_at'] }}
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">No reconciliation exceptions raised in this period.</p>
                    @endforelse
                    @if (count($exceptions) > $listPreviewLimit)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="exception-item" data-show-more-count="{{ count($exceptions) - $listPreviewLimit }}">
                            Show {{ count($exceptions) - $listPreviewLimit }} more
                        </button>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Audit cases opened</span>
                    <span class="badge text-bg-light border">{{ count($auditCases) }}</span>
                </div>
                <div class="card-body">
                    @forelse ($auditCases as $index => $case)
                        <div class="mb-3 pb-3 border-bottom auditcase-item" @if ($index >= $listPreviewLimit) hidden @endif>
                            <div class="d-flex justify-content-between align-items-start">
                                <strong>{{ $case['case_number'] }}</strong>
                                <x-status-badge :value="$case['risk_tier']" type="risk" />
                            </div>
                            <div class="text-muted small">{{ $case['case_type'] }} &middot; <x-status-badge :value="$case['status']" type="status" /></div>
                            <div class="small mt-1">{{ $case['title'] }}</div>
                            <div class="text-muted small mt-1">
                                Opened {{ $case['opened_at'] }}
                                @if ($case['closed_at'])
                                    &middot; Closed {{ $case['closed_at'] }}
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">No audit cases opened against this taxpayer during this period.</p>
                    @endforelse
                    @if (count($auditCases) > $listPreviewLimit)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="auditcase-item" data-show-more-count="{{ count($auditCases) - $listPreviewLimit }}">
                            Show {{ count($auditCases) - $listPreviewLimit }} more
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
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

@extends('layouts.app')

@section('title', 'VAT returns')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT return lifecycle</div>
    <h1 class="h3 mb-1">VAT periods &amp; returns</h1>
    <p class="text-muted mb-0">Generate, review and submit VAT returns from certified invoice activity, with maker-checker approval at every controlled step.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <label for="period-search" class="visually-hidden">Search periods by taxpayer, VAT number or period</label>
                        <input type="search" id="period-search" class="form-control" placeholder="Search taxpayer, VAT number or period">
                    </div>
                    <div class="col-md-3">
                        <label for="period-status-filter" class="visually-hidden">Filter by status</label>
                        <select id="period-status-filter" class="form-select">
                            <option value="ALL">All statuses</option>
                            <option value="OPEN">Open</option>
                            <option value="LOCKED">Locked</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <span id="period-count" class="text-muted small" aria-live="polite">{{ count($snapshot['periods']) }} period{{ count($snapshot['periods']) === 1 ? '' : 's' }}</span>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" id="period-table">
                    <caption class="visually-hidden">VAT periods, filterable by search text and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Period</th>
                            <th scope="col">Taxpayer</th>
                            <th scope="col">Due date</th>
                            <th scope="col" class="text-end">Reconciliation</th>
                            <th scope="col" class="text-end">Pending adjustments</th>
                            <th scope="col">Latest return</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['periods'] as $period)
                            <tr data-search="{{ mb_strtolower($period['period_code'].' '.$period['legal_name'].' '.$period['vat_number']) }}" data-status="{{ $period['status'] }}">
                                <td>
                                    <a href="{{ route('vat-periods.show', $period['id']) }}"><strong>{{ $period['period_code'] }}</strong></a>
                                    <div class="text-muted small">{{ \Illuminate\Support\Carbon::parse($period['period_start'])->format('d M') }} &ndash; {{ \Illuminate\Support\Carbon::parse($period['period_end'])->format('d M Y') }}</div>
                                </td>
                                <td>
                                    {{ $period['legal_name'] }}
                                    <div class="text-muted small">{{ $period['vat_number'] }}</div>
                                </td>
                                <td>{{ \Illuminate\Support\Carbon::parse($period['due_date'])->format('d M Y') }}</td>
                                <td class="text-end">
                                    <span class="text-success">{{ $period['matched_count'] }} matched</span>
                                    @if ($period['unmatched_count'] > 0)
                                        <br><span class="text-warning">{{ $period['unmatched_count'] }} unmatched</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($period['pending_adjustments'] > 0)
                                        <span class="badge text-bg-info">{{ $period['pending_adjustments'] }}</span>
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($period['latest_return_id'])
                                        <a href="{{ route('vat-returns.show', $period['latest_return_id']) }}">v{{ $period['latest_version'] }}</a>
                                        <div><x-status-badge :value="$period['return_status']" type="status" /></div>
                                    @else
                                        <span class="text-muted small">No return generated</span>
                                    @endif
                                </td>
                                <td><x-status-badge :value="$period['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No VAT periods yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p id="period-empty" class="text-center text-muted py-4 mb-0" hidden>No periods match this view. Adjust the search or status filter.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">ITAS submission provider</div>
            <div class="card-body">
                <dl class="row row-cols-1 mb-0">
                    <div class="col mb-2"><dt class="text-muted small d-inline">Configured:</dt> <dd class="d-inline mb-0">{{ $snapshot['provider']['configured'] ? 'Yes' : 'No' }}</dd></div>
                    <div class="col mb-2"><dt class="text-muted small d-inline">State:</dt> <dd class="d-inline mb-0"><x-status-badge :value="$snapshot['provider']['state']" type="status" /></dd></div>
                </dl>
                @unless ($snapshot['provider']['configured'])
                    <p class="text-muted small mb-0">Submissions will record as <strong>Blocked configuration</strong> until this integration is set up.</p>
                @endunless
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Pending approvals</div>
            <div class="card-body" style="max-height: 320px; overflow-y: auto;">
                @php $pending = collect($snapshot['approvals'])->where('status', 'PENDING'); @endphp
                @forelse ($pending as $task)
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between">
                            <strong>{{ ucwords(strtolower(str_replace('_', ' ', $task['requested_action']))) }}</strong>
                            <x-status-badge :value="$task['risk_tier']" type="risk" />
                        </div>
                        @if ($task['legal_name'])
                            <div class="text-muted small">{{ $task['legal_name'] }}</div>
                        @endif
                        <time class="text-muted small">Requested {{ \Illuminate\Support\Carbon::parse($task['requested_at'])->format('d M Y H:i') }}</time>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No approvals pending.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Recent submissions</div>
            <div class="card-body" style="max-height: 320px; overflow-y: auto;">
                @forelse (collect($snapshot['submissions'])->take(10) as $submission)
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start">
                            <strong>{{ $submission['period_code'] ?? 'Unknown period' }} v{{ $submission['version_number'] }}</strong>
                            <x-status-badge :value="$submission['status']" type="status" />
                        </div>
                        @if ($submission['last_error'])
                            <div class="text-danger small">{{ $submission['last_error'] }}</div>
                        @endif
                        <time class="text-muted small">{{ \Illuminate\Support\Carbon::parse($submission['requested_at'])->format('d M Y H:i') }}</time>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No submissions yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var search = document.getElementById('period-search');
        var status = document.getElementById('period-status-filter');
        var rows = Array.prototype.slice.call(document.querySelectorAll('#period-table tbody tr[data-search]'));
        var count = document.getElementById('period-count');
        var empty = document.getElementById('period-empty');
        if (!rows.length) { return; }

        function apply() {
            var q = search.value.trim().toLowerCase();
            var s = status.value;
            var visible = 0;
            rows.forEach(function (row) {
                var matches = row.dataset.search.indexOf(q) !== -1 && (s === 'ALL' || row.dataset.status === s);
                row.hidden = !matches;
                if (matches) { visible += 1; }
            });
            count.textContent = visible + ' period' + (visible === 1 ? '' : 's');
            empty.hidden = visible !== 0;
        }

        search.addEventListener('input', apply);
        status.addEventListener('change', apply);
    })();
</script>
@endpush

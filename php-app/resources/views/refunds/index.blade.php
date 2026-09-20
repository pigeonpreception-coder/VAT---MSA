@extends('layouts.app')

@section('title', 'Refund claims')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Refund domain</div>
    <h1 class="h3 mb-1">Refund claims</h1>
    <p class="text-muted mb-0">Every claim traces back to a filed VAT return's negative net position, with a frozen eligibility snapshot and full maker-checker history.</p>
</div>

@if ($outstanding)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold">Outstanding refund payments</div>
            <span class="badge {{ $outstanding['connector']['configured'] ? 'text-bg-success' : 'text-bg-secondary' }}">
                Payment connector: {{ $outstanding['connector']['configured'] ? 'Sandbox active' : 'Requires authority contract' }}
            </span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Claims that have cleared every review stage but have no payment instruction recorded yet -- what NamRA owes once Payment is authorised.</p>
            <div class="fs-4 fw-semibold">NAD {{ number_format($outstanding['total_outstanding_cents'] / 100, 2) }}</div>
        </div>
        @if (count($outstanding['claims']))
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Refund claims awaiting a recorded payment instruction</caption>
                    <thead><tr><th scope="col">Claim</th><th scope="col">Taxpayer</th><th scope="col">Amount</th><th scope="col">Approved</th></tr></thead>
                    <tbody>
                        @foreach ($outstanding['claims'] as $claim)
                            <tr>
                                <td><a href="{{ route('refunds.show', $claim['id']) }}">{{ $claim['claim_number'] }}</a></td>
                                <td>{{ $claim['legal_name'] }}</td>
                                <td>NAD {{ number_format(($claim['net_payable_cents'] ?? $claim['amount_cents']) / 100, 2) }}</td>
                                <td>{{ $claim['approved_at'] ? \Illuminate\Support\Carbon::parse($claim['approved_at'])->format('d M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

<div class="card">
    <div class="card-header">
        <div class="row g-2 align-items-center">
            <div class="col-md-6">
                <label for="claim-search" class="visually-hidden">Search claims by number, taxpayer or VAT number</label>
                <input type="search" id="claim-search" class="form-control" placeholder="Search claim number, taxpayer or VAT number">
            </div>
            <div class="col-md-3">
                <label for="claim-status-filter" class="visually-hidden">Filter by status</label>
                <select id="claim-status-filter" class="form-select">
                    <option value="ALL">All statuses</option>
                    <option value="BLOCKED_RETURN_NOT_FILED">Blocked return not filed</option>
                    <option value="RECEIVED">Received</option>
                    <option value="RISK_REVIEW">Risk review</option>
                    <option value="OFFICER_REVIEW">Officer review</option>
                    <option value="PAYMENT_AUTHORISATION">Payment authorisation</option>
                    <option value="EVIDENCE_REQUESTED">Evidence requested</option>
                    <option value="ON_HOLD">On hold</option>
                    <option value="REJECTED">Rejected</option>
                    <option value="DISPUTED">Disputed</option>
                    <option value="PAYMENT_PENDING">Payment pending</option>
                    <option value="CLOSED">Closed</option>
                </select>
            </div>
            <div class="col-md-3 text-md-end">
                <span id="claim-count" class="text-muted small" aria-live="polite">{{ count($claims) }} claim{{ count($claims) === 1 ? '' : 's' }}</span>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" id="claim-table">
            <caption class="visually-hidden">Refund claims, filterable by search text and status</caption>
            <thead>
                <tr>
                    <th scope="col">Claim</th>
                    <th scope="col">Taxpayer</th>
                    <th scope="col" class="text-end">Amount</th>
                    <th scope="col">Risk</th>
                    <th scope="col">Status</th>
                    <th scope="col">Requested</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($claims as $claim)
                    <tr data-search="{{ mb_strtolower($claim['claim_number'].' '.$claim['legal_name'].' '.$claim['vat_number']) }}" data-status="{{ $claim['status'] }}">
                        <td><a href="{{ route('refunds.show', $claim['id']) }}"><strong>{{ $claim['claim_number'] }}</strong></a></td>
                        <td>
                            {{ $claim['legal_name'] }}
                            <div class="text-muted small">{{ $claim['vat_number'] }}</div>
                        </td>
                        <td class="text-end">{{ $claim['currency'] }} {{ number_format($claim['amount_cents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$claim['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$claim['status']" type="status" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($claim['requested_at'])->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No refund claims yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p id="claim-empty" class="text-center text-muted py-4 mb-0" hidden>No claims match this view. Adjust the search or status filter.</p>
    </div>
</div>

<div class="mt-5">
    <div class="text-uppercase text-muted small fw-semibold">VAT Refund Report</div>
    <h2 class="h4 mb-3">NamRA VAT Summary Report</h2>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('refunds.index') }}" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label for="namra-period-id" class="form-label">VAT period</label>
                    <select name="period_id" id="namra-period-id" class="form-select" onchange="this.form.submit()">
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected(($namraSummary['period']['id'] ?? null) === $period->id)>
                                {{ $period->taxpayer?->legal_name }} &mdash; {{ $period->period_code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if (! $namraSummary)
        <div class="card"><div class="card-body text-center text-muted py-4">No VAT period is available to report on.</div></div>
    @else
        @php
            $np = $namraSummary['period'];
            $nfmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
        @endphp
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-muted small">TIN</div>
                        <div class="fw-semibold">{{ $np['tin'] }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Taxpayer</div>
                        <div class="fw-semibold">{{ $np['legal_name'] }}</div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">Tax type</div>
                        <div>Value Added Tax</div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">Return period</div>
                        <div>{{ $np['period_code'] }}</div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">Due date</div>
                        <div>{{ $np['due_date'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><strong>VAT declared (outputs)</strong></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">VAT declared by category</caption>
                            <thead>
                                <tr><th scope="col">Category</th><th scope="col" class="text-end">Sales (excl. VAT)</th><th scope="col" class="text-end">Output tax due</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($namraSummary['output']['categories'] as $category)
                                    <tr>
                                        <td>{{ $category['label'] }}</td>
                                        <td class="text-end">{{ $nfmt($category['taxable_cents']) }}</td>
                                        <td class="text-end">{{ $nfmt($category['vat_cents']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="fw-semibold table-light">
                                    <td>Total</td>
                                    <td class="text-end">{{ $nfmt($namraSummary['output']['total_taxable_cents']) }}</td>
                                    <td class="text-end">{{ $nfmt($namraSummary['output']['total_vat_cents']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><strong>VAT claimed (inputs)</strong></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">VAT claimed by category</caption>
                            <thead>
                                <tr><th scope="col">Category</th><th scope="col" class="text-end">Purchases (excl. VAT)</th><th scope="col" class="text-end">Input tax claimed</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($namraSummary['input']['categories'] as $category)
                                    <tr>
                                        <td>{{ $category['label'] }}</td>
                                        <td class="text-end">{{ $nfmt($category['taxable_cents']) }}</td>
                                        <td class="text-end">{{ $nfmt($category['vat_cents']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="fw-semibold table-light">
                                    <td>Total</td>
                                    <td class="text-end">{{ $nfmt($namraSummary['input']['total_taxable_cents']) }}</td>
                                    <td class="text-end">{{ $nfmt($namraSummary['input']['total_vat_cents']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>VAT payable</strong></div>
            <div class="card-body">
                @if ($namraSummary['filed_return'])
                    @php $f = $namraSummary['filed_return']; @endphp
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Total output tax due</div>
                            <div class="fw-semibold">{{ $nfmt($f['output_tax_cents']) }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Less input tax claimed</div>
                            <div class="fw-semibold">{{ $nfmt($f['input_tax_cents']) }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Amount due / (repayable)</div>
                            <div class="h5 mb-0">{{ $nfmt($f['net_payable_cents']) }}</div>
                        </div>
                    </div>
                @else
                    <div class="text-muted small">No VAT return has been generated for this period yet.</div>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var search = document.getElementById('claim-search');
        var status = document.getElementById('claim-status-filter');
        var rows = Array.prototype.slice.call(document.querySelectorAll('#claim-table tbody tr[data-search]'));
        var count = document.getElementById('claim-count');
        var empty = document.getElementById('claim-empty');
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
            count.textContent = visible + ' claim' + (visible === 1 ? '' : 's');
            empty.hidden = visible !== 0;
        }

        search.addEventListener('input', apply);
        status.addEventListener('change', apply);
    })();
</script>
@endpush

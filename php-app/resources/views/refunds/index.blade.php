@extends('layouts.app')

@section('title', 'Refund claims')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Refund domain</div>
    <h1 class="h3 mb-1">Refund claims</h1>
    <p class="text-muted mb-0">Every claim traces back to a filed VAT return's negative net position, with a frozen eligibility snapshot and full maker-checker history.</p>
</div>

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

@extends('layouts.app')

@section('title', 'Tax invoices')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Invoice domain</div>
    <h1 class="h3 mb-1">Certified tax invoices</h1>
    <p class="text-muted mb-0">Every accepted document is validated, certified and linked to a controlled VAT transaction.</p>
</div>

<div class="card">
    <div class="card-header">
        <div class="row g-2 align-items-center">
            <div class="col-md-6">
                <label for="invoice-search" class="visually-hidden">Search invoices by number, supplier or VAT number</label>
                <input type="search" id="invoice-search" class="form-control" placeholder="Search invoice, supplier or VAT number">
            </div>
            <div class="col-md-3">
                <label for="invoice-status-filter" class="visually-hidden">Filter by status</label>
                <select id="invoice-status-filter" class="form-select">
                    <option value="ALL">All statuses</option>
                    <option value="MATCHED">Matched</option>
                    <option value="CERTIFIED">Certified</option>
                    <option value="EXCEPTION">Exception</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
            </div>
            <div class="col-md-3 text-md-end">
                {{-- aria-live announces the filtered count to screen-reader users as they type/select, without moving focus. --}}
                <span id="invoice-count" class="text-muted small" aria-live="polite">{{ count($invoices) }} document{{ count($invoices) === 1 ? '' : 's' }}</span>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" id="invoice-table">
            <caption class="visually-hidden">Certified tax invoices, filterable by search text and status</caption>
            <thead>
                <tr>
                    <th scope="col">Invoice</th>
                    <th scope="col">Issue date</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Customer</th>
                    <th scope="col" class="text-end">Net value</th>
                    <th scope="col" class="text-end">VAT</th>
                    <th scope="col" class="text-end">Total</th>
                    <th scope="col">Status</th>
                    <th scope="col">Risk</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr data-search="{{ mb_strtolower($invoice['invoiceNumber'].' '.$invoice['supplierName'].' '.$invoice['supplierVatNumber'].' '.$invoice['customerName'].' '.($invoice['customerVatNumber'] ?? '')) }}" data-status="{{ $invoice['status'] }}">
                        <td>
                            <a href="{{ route('invoices.show', $invoice['id']) }}"><strong>{{ $invoice['invoiceNumber'] }}</strong></a>
                            <div class="text-muted small font-monospace">{{ $invoice['id'] }}</div>
                        </td>
                        <td>{{ \Illuminate\Support\Carbon::parse($invoice['issueDate'])->format('d M Y') }}</td>
                        <td>
                            {{ $invoice['supplierName'] }}
                            <div class="text-muted small">{{ $invoice['supplierVatNumber'] }}</div>
                        </td>
                        <td>
                            {{ $invoice['customerName'] }}
                            <div class="text-muted small">{{ $invoice['customerVatNumber'] ?? 'Not VAT registered' }}</div>
                        </td>
                        <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['lineNetCents'] / 100, 2) }}</td>
                        <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['taxCents'] / 100, 2) }}</td>
                        <td class="text-end">{{ $invoice['currency'] }} {{ number_format($invoice['totalCents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$invoice['status']" type="status" /></td>
                        <td><x-status-badge :value="$invoice['riskLevel']" type="risk" /></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p id="invoice-empty" class="text-center text-muted py-4 mb-0" hidden>No invoices match this view. Adjust the search or status filter.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var search = document.getElementById('invoice-search');
        var status = document.getElementById('invoice-status-filter');
        var rows = Array.prototype.slice.call(document.querySelectorAll('#invoice-table tbody tr[data-search]'));
        var count = document.getElementById('invoice-count');
        var empty = document.getElementById('invoice-empty');
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
            count.textContent = visible + ' document' + (visible === 1 ? '' : 's');
            empty.hidden = visible !== 0;
        }

        search.addEventListener('input', apply);
        status.addEventListener('change', apply);
    })();
</script>
@endpush

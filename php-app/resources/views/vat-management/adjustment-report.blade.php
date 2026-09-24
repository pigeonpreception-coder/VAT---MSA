@extends('layouts.app')

@section('title', 'VAT Adjustment Report')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT Management</div>
    <h1 class="h3 mb-1">VAT Adjustment Report</h1>
    <p class="text-muted mb-0">Credit and debit note corrections issued in a period, alongside manual VAT adjustments reconciled against the filed return.</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('vat-management.adjustment-report') }}" class="row g-2 align-items-end">
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
        $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
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

    @foreach ([['key' => 'output_corrections', 'title' => 'Credit & Debit Note Corrections — Output (issued to customers)'], ['key' => 'input_corrections', 'title' => 'Credit & Debit Note Corrections — Input (received from suppliers)']] as $side)
        @php $section = $report[$side['key']]; @endphp
        <div class="card mb-4">
            <div class="card-header"><strong>{{ $side['title'] }}</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">{{ $side['title'] }} line items</caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Type</th>
                            <th scope="col">Original Invoice</th>
                            <th scope="col">Correction Invoice</th>
                            <th scope="col">Party</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Taxable</th>
                            <th scope="col" class="text-end">VAT</th>
                            <th scope="col" class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['items'] as $item)
                            <tr>
                                <td>{{ $item['date'] }}</td>
                                <td>{{ $item['correction_type'] === 'CREDIT_NOTE' ? 'Credit Note' : 'Debit Note' }}</td>
                                <td>{{ $item['original_invoice_number'] }}</td>
                                <td>{{ $item['correction_invoice_number'] }}</td>
                                <td>{{ $item['party_name'] }}</td>
                                <td>{{ $item['reason_code'] }}<div class="text-muted small">{{ $item['reason'] }}</div></td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                                <td class="text-end">{{ $fmt($item['taxable_cents']) }}</td>
                                <td class="text-end">{{ $fmt($item['vat_cents']) }}</td>
                                <td class="text-end">{{ $fmt($item['total_cents']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No credit or debit note corrections in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between fw-semibold">
                <span>{{ $section['credit_note_count'] }} credit note(s), {{ $section['debit_note_count'] }} debit note(s)</span>
                <span>Net effect: {{ $fmt($section['net_vat_cents']) }} VAT on {{ $fmt($section['net_taxable_cents']) }} taxable</span>
            </div>
        </div>
    @endforeach

    @php $adjustments = $report['period_adjustments']; @endphp
    <div class="card mb-4">
        <div class="card-header"><strong>Period VAT Adjustments</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Manual VAT adjustments for this period</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Type</th>
                        <th scope="col">Direction</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($adjustments['items'] as $item)
                        <tr>
                            <td>{{ $item['created_at'] }}</td>
                            <td>{{ $item['adjustment_type'] }}</td>
                            <td>{{ $item['direction'] }}</td>
                            <td>{{ $item['reason_code'] }}<div class="text-muted small">{{ $item['explanation'] }}</div></td>
                            <td><x-status-badge :value="$item['status']" type="status" /></td>
                            <td class="text-end">{{ $fmt($item['signed_cents']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No manual VAT adjustments submitted for this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between fw-semibold">
            <span>{{ $adjustments['pending_count'] }} pending approval</span>
            <span>Approved net: {{ $fmt($adjustments['approved_net_cents']) }}</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Adjustment reconciliation</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Approved period adjustments (computed)</div>
                    <div class="h5 mb-0">{{ $fmt($adjustments['approved_net_cents']) }}</div>
                </div>
                @if ($report['filed_return'])
                    <div class="col-md-4">
                        <div class="text-muted small">Filed VAT return (v{{ $report['filed_return']['version_number'] }}, {{ $report['filed_return']['status'] }})</div>
                        <div class="h5 mb-0">{{ $fmt($report['filed_return']['adjustment_cents']) }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Discrepancy</div>
                        @if ($report['discrepancy_cents'] === 0)
                            <div class="h5 mb-0 text-success">Reconciled &mdash; no discrepancy</div>
                        @else
                            <div class="h5 mb-0 text-danger">
                                {{ $fmt(abs($report['discrepancy_cents'])) }}
                                {{ $report['discrepancy_cents'] > 0 ? 'more than filed' : 'less than filed' }}
                            </div>
                        @endif
                    </div>
                @else
                    <div class="col-md-8">
                        <div class="text-muted small">No VAT return has been generated for this period yet.</div>
                        <a href="{{ route('vat-periods.show', $p['id']) }}" class="btn btn-sm btn-outline-primary mt-2">Go to VAT period</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
@endsection

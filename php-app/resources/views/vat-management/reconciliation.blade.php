@extends('layouts.app')

@section('title', 'Invoice Reconciliation Report')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT Management</div>
    <h1 class="h3 mb-1">Invoice Reconciliation Report</h1>
    <p class="text-muted mb-0">Certified invoice totals by VAT category for one period, reconciled against the taxpayer's filed VAT return.</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('vat-management.reconciliation') }}" class="row g-2 align-items-end">
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
                    <div class="text-muted small">Audit period</div>
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

    @foreach ([['key' => 'output', 'title' => 'Output VAT (Sales)'], ['key' => 'input', 'title' => 'Input VAT (Purchases)']] as $side)
        @php $section = $report[$side['key']]; @endphp
        <div class="card mb-4">
            <div class="card-header"><strong>{{ $side['title'] }}</strong></div>
            @forelse ($section['categories'] as $category)
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <caption class="visually-hidden">{{ $category['label'] }} line items</caption>
                        <thead>
                            <tr class="table-light">
                                <th colspan="7" class="text-uppercase small">{{ $category['label'] }}</th>
                            </tr>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Invoice No.</th>
                                <th scope="col">Party</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Taxable</th>
                                <th scope="col" class="text-end">VAT</th>
                                <th scope="col" class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($category['lines'] as $line)
                                <tr>
                                    <td>{{ $line['date'] }}</td>
                                    <td>{{ $line['invoice_number'] }}</td>
                                    <td>{{ $line['party_name'] }}</td>
                                    <td>{{ $line['description'] }}</td>
                                    <td class="text-end">{{ $fmt($line['taxable_cents']) }}</td>
                                    <td class="text-end">{{ $fmt($line['vat_cents']) }}</td>
                                    <td class="text-end">{{ $fmt($line['taxable_cents'] + $line['vat_cents']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="fw-semibold table-light">
                                <td colspan="4">Subtotal &mdash; {{ $category['label'] }}</td>
                                <td class="text-end">{{ $fmt($category['taxable_cents']) }}</td>
                                <td class="text-end">{{ $fmt($category['vat_cents']) }}</td>
                                <td class="text-end">{{ $fmt($category['total_cents']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @empty
                <div class="card-body text-muted small">No {{ strtolower($side['title']) }} invoices in this period.</div>
            @endforelse
            <div class="card-footer d-flex justify-content-between fw-semibold">
                <span>Grand total &mdash; {{ $side['title'] }}</span>
                <span>{{ $fmt($section['total_vat_cents']) }} VAT on {{ $fmt($section['total_taxable_cents']) }} taxable</span>
            </div>
        </div>
    @endforeach

    <div class="card">
        <div class="card-header"><strong>VAT payable reconciliation</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Computed from certified invoices</div>
                    <div class="h5 mb-0">{{ $fmt($report['computed_net_payable_cents']) }}</div>
                </div>
                @if ($report['filed_return'])
                    <div class="col-md-4">
                        <div class="text-muted small">Filed VAT return (v{{ $report['filed_return']['version_number'] }}, {{ $report['filed_return']['status'] }})</div>
                        <div class="h5 mb-0">{{ $fmt($report['filed_return']['net_payable_cents']) }}</div>
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

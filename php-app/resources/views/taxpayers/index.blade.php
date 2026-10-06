@extends('layouts.app')

@section('title', 'Taxpayer registry')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Identity and taxpayer domain</div>
    <h1 class="h3 mb-1">Canonical taxpayer registry</h1>
    <p class="text-muted mb-0">One legal taxpayer identity resolves to one organisation used across every buyer and seller transaction role.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Taxpayers</span><span>T</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['total']) }}</div>
                <div class="small text-muted">Canonical registrations</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active</span><span>&check;</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['active']) }}</div>
                <div class="small text-muted">In good VAT standing</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Suspended</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['suspended']) }}</div>
                <div class="small text-muted">VAT status enforcement</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Transactions</span><span>#</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['transactions']) }}</div>
                <div class="small text-muted">As buyer or seller</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Pilot participants</div>
                <div class="text-muted small">{{ count($taxpayers) }} active registrations with canonical organisation mappings</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Taxpayers, their identifiers, capabilities, transaction counts and VAT position</caption>
                    <thead>
                        <tr>
                            <th scope="col">Taxpayer</th>
                            <th scope="col">VAT number &amp; TIN</th>
                            <th scope="col">Capabilities &amp; frequency</th>
                            <th scope="col" class="text-end">Activity</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($taxpayers as $taxpayer)
                            <tr>
                                <td>
                                    @if ($taxpayer['organisation_id'])
                                        <a href="{{ route('organisations.show', $taxpayer['organisation_id']) }}"><strong>{{ $taxpayer['legal_name'] }}</strong></a>
                                    @else
                                        <strong>{{ $taxpayer['legal_name'] }}</strong>
                                    @endif
                                    <div class="text-muted small">{{ $taxpayer['trading_name'] ?? $taxpayer['email'] }}</div>
                                </td>
                                <td>
                                    <span class="font-monospace">{{ $taxpayer['vat_number'] }}</span>
                                    <div class="text-muted small font-monospace">{{ $taxpayer['tin'] }}</div>
                                </td>
                                <td>
                                    @foreach (array_filter(explode(',', $taxpayer['capabilities'] ?? '')) as $capability)
                                        <x-status-badge :value="$capability" type="status" />
                                    @endforeach
                                    <div class="text-muted small">{{ str_replace('_', ' ', $taxpayer['return_frequency']) }}</div>
                                </td>
                                <td class="text-end">
                                    {{ number_format($taxpayer['transaction_count']) }}
                                    <div class="text-muted small">{{ $tenantCurrencySymbol }} {{ number_format($taxpayer['output_tax_cents'] / 100, 2) }} out &middot; {{ $tenantCurrencySymbol }} {{ number_format($taxpayer['input_tax_cents'] / 100, 2) }} in</div>
                                </td>
                                <td><x-status-badge :value="$taxpayer['vat_status']" type="taxpayer" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No taxpayers are registered.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">Taxpayers by capability</div>
            <div class="card-body">
                @forelse ($byCapability as $row)
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <span>{{ ucwords(strtolower($row['capability'])) }}</span>
                        <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No capability grants recorded yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Taxpayers by frequency</div>
            <div class="card-body">
                @forelse ($byFrequency as $row)
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <span>{{ ucwords(strtolower(str_replace('_', ' ', $row['frequency']))) }}</span>
                        <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No taxpayers recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

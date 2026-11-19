@extends('layouts.app')

@section('title', 'Taxpayers')

@section('content')
<x-page-header title="Taxpayers"
    subtitle="The national taxpayer register. Each taxpayer has one organisation, used for all of its buying and selling." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="stat-label"><span>Taxpayers</span></div>
                <div class="stat-value">{{ number_format($metrics['total']) }}</div>
                <div class="small text-muted">Registered for VAT</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="stat-label"><span>Active</span></div>
                <div class="stat-value">{{ number_format($metrics['active']) }}</div>
                <div class="small text-muted">In good standing</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="stat-label"><span>Suspended</span></div>
                <div class="stat-value">{{ number_format($metrics['suspended']) }}</div>
                <div class="small text-muted">VAT status suspended</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="stat-label"><span>Transactions</span></div>
                <div class="stat-value">{{ number_format($metrics['transactions']) }}</div>
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
                <div class="text-muted small">{{ count($taxpayers) }} active {{ \Illuminate\Support\Str::plural('registration', count($taxpayers)) }}, each linked to its organisation</div>
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
                                    <div class="text-muted small">@humanize($taxpayer['return_frequency'])</div>
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
                        <span>@humanize($row['capability'])</span>
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
                        <span>@humanize($row['frequency'])</span>
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

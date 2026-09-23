@extends('layouts.app')

@section('title', 'Taxpayer registry')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Identity and taxpayer domain</div>
    <h1 class="h3 mb-1">Canonical taxpayer registry</h1>
    <p class="text-muted mb-0">One legal taxpayer identity resolves to one organisation used across every buyer and seller transaction role.</p>
</div>

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
                    <th scope="col">VAT number</th>
                    <th scope="col">TIN</th>
                    <th scope="col">Capabilities</th>
                    <th scope="col">Frequency</th>
                    <th scope="col" class="text-end">Transactions</th>
                    <th scope="col" class="text-end">Output VAT</th>
                    <th scope="col" class="text-end">Input VAT</th>
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
                        <td class="font-monospace">{{ $taxpayer['vat_number'] }}</td>
                        <td class="font-monospace">{{ $taxpayer['tin'] }}</td>
                        <td>
                            @foreach (array_filter(explode(',', $taxpayer['capabilities'] ?? '')) as $capability)
                                <x-status-badge :value="$capability" type="status" />
                            @endforeach
                        </td>
                        <td>{{ $taxpayer['return_frequency'] }}</td>
                        <td class="text-end">{{ number_format($taxpayer['transaction_count']) }}</td>
                        <td class="text-end">N$ {{ number_format($taxpayer['output_tax_cents'] / 100, 2) }}</td>
                        <td class="text-end">N$ {{ number_format($taxpayer['input_tax_cents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$taxpayer['vat_status']" type="taxpayer" /></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No taxpayers are registered.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

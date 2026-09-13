@extends('layouts.app')

@section('title', 'Foreign invoices')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Invoice Management</div>
    <h1 class="h3 mb-1">Foreign invoices</h1>
    <p class="text-muted mb-0">Autonomously pulled from NamRA's E-Tariff border system and cross-authenticated against the duty-paid record captured at import/export, so a foreign invoice's VAT audit rests on independent evidence, not the taxpayer's own submission alone.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-warning" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">E-Tariff integration</div>
        <div class="text-muted small">NamRA's border/customs declaration system</div>
    </div>
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <x-status-badge :value="$etariffStatus['configured'] ? 'CONFIGURED' : 'NOT_CONFIGURED'" type="status" />
            <span class="ms-2 text-muted small">{{ str_replace('_', ' ', $etariffStatus['state']) }}</span>
            @unless ($etariffStatus['configured'])
                <div class="text-muted small mt-1">Awaiting a confirmed technical contract with NamRA's border/customs systems. No foreign-invoice data can be pulled until this is configured.</div>
            @endunless
        </div>
        @if ($canPull)
            <form method="POST" action="{{ route('invoice-management.foreign.pull') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary">Pull from E-Tariff</button>
            </form>
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Foreign invoice register</div>
        <div class="text-muted small">Customs declarations backing each foreign invoice, and whether each has been verified against E-Tariff</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Foreign invoice customs declarations with their supplier/origin, customs value, import VAT, source, verification status and last E-Tariff pull time</caption>
            <thead>
                <tr>
                    <th scope="col">Declaration</th>
                    <th scope="col">Supplier / origin</th>
                    <th scope="col">Customs value</th>
                    <th scope="col">Import VAT</th>
                    <th scope="col">Date</th>
                    <th scope="col">Source</th>
                    <th scope="col">Verification</th>
                    <th scope="col">Last pulled</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="font-monospace">
                            <strong>{{ $record->declaration_number }}</strong>
                            @if ($record->etariff_reference)
                                <div class="text-muted small">E-Tariff ref {{ $record->etariff_reference }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $record->supplier_name }}
                            <div class="text-muted small">{{ $record->country_of_origin }}</div>
                        </td>
                        <td>{{ $record->currency }} {{ number_format($record->customs_value_cents / 100, 2) }}</td>
                        <td>{{ $record->currency }} {{ number_format($record->import_vat_cents / 100, 2) }}</td>
                        <td>{{ $record->declaration_date->toDateString() }}</td>
                        <td>{{ str_replace('_', ' ', $record->source) }}</td>
                        <td><x-status-badge :value="$record->verification_status" type="status" /></td>
                        <td>{{ $record->pulled_at?->format('d M Y, H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No foreign invoice declarations yet. @if ($canPull) Use "Pull from E-Tariff" above once the integration is configured. @endif</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

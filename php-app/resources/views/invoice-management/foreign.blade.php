@extends('layouts.app')

@section('title', 'Foreign invoices')

@section('content')
<x-page-header title="Foreign invoices"
    :subtitle="'Invoices for imported goods, pulled from '.$tenantAuthorityShortName.'\'s E-Tariff customs system and checked against the duty-paid declaration — so their VAT rests on independent evidence, not only on what the taxpayer submitted.'">
    @if ($canPull)
        <x-slot:actions>
            <form method="POST" action="{{ route('invoice-management.foreign.pull') }}">
                @csrf
                <x-idempotency-key/>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-cloud-download" aria-hidden="true"></i> Pull from E-Tariff</button>
            </form>
        </x-slot:actions>
    @endif
</x-page-header>

<x-form-errors />

<div class="card mb-3">
    <div class="card-body d-flex align-items-start gap-3">
        <span class="icon-circle icon-circle-sm {{ $etariffStatus['configured'] ? '' : 'icon-circle-warning' }}"><i class="bi bi-globe2" aria-hidden="true"></i></span>
        <div>
            <div class="fw-semibold">E-Tariff connection <x-status-badge :value="$etariffStatus['configured'] ? 'CONFIGURED' : 'NOT_CONFIGURED'" type="status" class="ms-1" /></div>
            <div class="text-muted small">@humanize($etariffStatus['state'])</div>
            @unless ($etariffStatus['configured'])
                <div class="text-muted small mt-1">Waiting for a confirmed technical contract with {{ $tenantAuthorityShortName }}'s customs systems. Foreign invoices can't be pulled until it's set up.</div>
            @endunless
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Foreign invoice register</div>
        <div class="text-muted small">Customs declarations behind each foreign invoice and whether E-Tariff has verified them</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Foreign invoice customs declarations with their supplier/origin, customs value, import VAT, source, verification status and last E-Tariff pull time</caption>
            <thead>
                <tr>
                    <th scope="col">Declaration</th>
                    <th scope="col">Supplier / origin</th>
                    <th scope="col" class="text-end">Customs value</th>
                    <th scope="col" class="text-end">Import VAT</th>
                    <th scope="col">Date</th>
                    <th scope="col">Source</th>
                    <th scope="col">Verification</th>
                    <th scope="col">Last pulled</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $record->declaration_number }}</span>
                            @if ($record->etariff_reference)
                                <div class="text-muted small">E-Tariff ref {{ $record->etariff_reference }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $record->supplier_name }}
                            <div class="text-muted small">{{ $record->country_of_origin }}</div>
                        </td>
                        <td class="text-end text-nowrap">{{ $record->currency }} {{ number_format($record->customs_value_cents / 100, 2) }}</td>
                        <td class="text-end text-nowrap">{{ $record->currency }} {{ number_format($record->import_vat_cents / 100, 2) }}</td>
                        <td class="text-nowrap">{{ $record->declaration_date->format('d M Y') }}</td>
                        <td>@humanize($record->source)</td>
                        <td><x-status-badge :value="$record->verification_status" type="status" /></td>
                        <td class="text-nowrap">{{ $record->pulled_at?->format('d M Y, H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">No foreign invoices yet. @if ($canPull) Use "Pull from E-Tariff" once the connection is set up. @endif</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

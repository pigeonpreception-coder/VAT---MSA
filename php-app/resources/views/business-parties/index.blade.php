@extends('layouts.app')

@section('title', 'Business parties')

@php
    $openRegisterForm = $errors->any() || old('display_name') !== null;
    $preselectedRelationships = old('relationships', ($filters['relationship'] ?? '') !== '' ? [$filters['relationship']] : []);
@endphp

@section('content')
<x-page-header :title="['CUSTOMER' => 'Customers', 'SUPPLIER' => 'Suppliers', 'SERVICE_PROVIDER' => 'Service providers'][$filters['relationship'] ?? ''] ?? 'Business parties'"
        subtitle="Organisations you trade with. Open a party to verify it against the national taxpayer register.">
    <x-slot:actions>
        <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#register-party"
            aria-expanded="{{ $openRegisterForm ? 'true' : 'false' }}" aria-controls="register-party">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Register a party
        </button>
    </x-slot:actions>
</x-page-header>

<div class="collapse @if ($openRegisterForm) show @endif" id="register-party">
<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Register a party</div>
        <div class="text-muted small">Only a display name and a relationship are required. You can verify the party afterwards.</div>
    </div>
    <div class="card-body">
        <x-form-errors />
        <form method="POST" action="{{ route('business-parties.store') }}" class="row g-2">
                        @csrf
            <x-idempotency-key />
            <div class="col-md-3">
                <label for="display_name" class="form-label small mb-0">Display name</label>
                <input type="text" id="display_name" name="display_name" value="{{ old('display_name') }}" class="form-control form-control-sm @error('display_name') is-invalid @enderror" required>
                @error('display_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="legal_name" class="form-label small mb-0">Legal name (optional)</label>
                <input type="text" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" class="form-control form-control-sm @error('legal_name') is-invalid @enderror">
                @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="vat_number" class="form-label small mb-0">VAT number (optional)</label>
                <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control form-control-sm @error('vat_number') is-invalid @enderror">
                @error('vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="company_registration_number" class="form-label small mb-0">Company reg. no. (optional)</label>
                <input type="text" id="company_registration_number" name="company_registration_number" value="{{ old('company_registration_number') }}" class="form-control form-control-sm @error('company_registration_number') is-invalid @enderror">
                @error('company_registration_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="email" class="form-label small mb-0">Email (optional)</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control form-control-sm @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0 d-block">Relationship</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rel_customer" name="relationships[]" value="CUSTOMER" @checked(in_array('CUSTOMER', $preselectedRelationships, true))>
                    <label class="form-check-label small" for="rel_customer">Customer</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rel_supplier" name="relationships[]" value="SUPPLIER" @checked(in_array('SUPPLIER', $preselectedRelationships, true))>
                    <label class="form-check-label small" for="rel_supplier">Supplier</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rel_service_provider" name="relationships[]" value="SERVICE_PROVIDER" @checked(in_array('SERVICE_PROVIDER', $preselectedRelationships, true))>
                    <label class="form-check-label small" for="rel_service_provider">Service provider</label>
                </div>
                @error('relationships')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label for="address" class="form-label small mb-0">Address (optional)</label>
                <input type="text" id="address" name="address" value="{{ old('address') }}" class="form-control form-control-sm @error('address') is-invalid @enderror">
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
                        <div class="col-md-4 align-self-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">Register party</button>
            </div>
        </form>
    </div>
</div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('business-parties.index') }}" class="row g-2 align-items-center">
            <div class="col-md-3">
                <label for="relationship" class="form-label small mb-0">Relationship</label>
                <select id="relationship" name="relationship" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected(($filters['relationship'] ?? '') === '')>All</option>
                    <option value="CUSTOMER" @selected(($filters['relationship'] ?? '') === 'CUSTOMER')>Customer</option>
                    <option value="SUPPLIER" @selected(($filters['relationship'] ?? '') === 'SUPPLIER')>Supplier</option>
                    <option value="SERVICE_PROVIDER" @selected(($filters['relationship'] ?? '') === 'SERVICE_PROVIDER')>Service provider</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected(($filters['status'] ?? '') === '')>All</option>
                    <option value="ACTIVE" @selected(($filters['status'] ?? '') === 'ACTIVE')>Active</option>
                    <option value="INACTIVE" @selected(($filters['status'] ?? '') === 'INACTIVE')>Inactive</option>
                </select>
            </div>
            <div class="col-md-6 text-md-end small text-muted">{{ $totalCount }} part{{ $totalCount === 1 ? 'y' : 'ies' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Business parties, filterable by relationship and status</caption>
            <thead>
                <tr>
                    <th scope="col">Party</th>
                    <th scope="col">VAT number</th>
                    <th scope="col">Relationships</th>
                    <th scope="col">Status</th>
                    <th scope="col">Counterparty trust</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($parties as $party)
                    <tr>
                        <td><a href="{{ route('business-parties.show', $party['id']) }}"><strong>{{ $party['display_name'] }}</strong></a></td>
                        <td class="font-monospace">{{ $party['vat_number'] ?? '—' }}</td>
                        <td>
                            @foreach ($party['relationships'] as $relationship)
                                <span class="badge text-bg-light border">@humanize($relationship)</span>
                            @endforeach
                        </td>
                        <td><x-status-badge :value="$party['status']" type="status" /></td>
                        <td>
                            <span class="badge text-bg-light border">@humanize($party['trust_status'] ?? 'PENDING_PROVIDER')</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No business parties match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Business Parties')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Business domain</div>
    <h1 class="h3 mb-1">Business Parties</h1>
    <p class="text-muted mb-0">Customers and suppliers trading with this organisation. A supplier can be verified against the national taxpayer register directly from its own page.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6">Register a party</h2>
        @if ($errors->any())
            <div class="alert alert-danger py-2" role="alert">
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('business-parties.store') }}" class="row g-2">
            @csrf
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
                <label for="email" class="form-label small mb-0">Email (optional)</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control form-control-sm @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0 d-block">Relationship</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rel_customer" name="relationships[]" value="CUSTOMER" @checked(in_array('CUSTOMER', old('relationships', [])))>
                    <label class="form-check-label small" for="rel_customer">Customer</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rel_supplier" name="relationships[]" value="SUPPLIER" @checked(in_array('SUPPLIER', old('relationships', [])))>
                    <label class="form-check-label small" for="rel_supplier">Supplier</label>
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

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('business-parties.index') }}" class="row g-2 align-items-center">
            <div class="col-md-3">
                <label for="relationship" class="form-label small mb-0">Relationship</label>
                <select id="relationship" name="relationship" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected(($filters['relationship'] ?? '') === '')>All</option>
                    <option value="CUSTOMER" @selected(($filters['relationship'] ?? '') === 'CUSTOMER')>Customer</option>
                    <option value="SUPPLIER" @selected(($filters['relationship'] ?? '') === 'SUPPLIER')>Supplier</option>
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
                </tr>
            </thead>
            <tbody>
                @forelse ($parties as $party)
                    <tr>
                        <td><a href="{{ route('business-parties.show', $party['id']) }}"><strong>{{ $party['display_name'] }}</strong></a></td>
                        <td class="font-monospace">{{ $party['vat_number'] ?? '—' }}</td>
                        <td>
                            @foreach ($party['relationships'] as $relationship)
                                <span class="badge text-bg-light border">{{ ucfirst(strtolower($relationship)) }}</span>
                            @endforeach
                        </td>
                        <td><x-status-badge :value="$party['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No business parties match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

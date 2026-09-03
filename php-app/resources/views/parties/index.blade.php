@extends('layouts.app')

@section('title', 'Customers & suppliers')

@php
    $parties = $snapshot['parties'];
    $activeCount = collect($parties)->where('status', 'ACTIVE')->count();
    $customerCount = collect($parties)->filter(fn ($p) => in_array('CUSTOMER', $p['relationships'], true))->count();
    $supplierCount = collect($parties)->filter(fn ($p) => in_array('SUPPLIER', $p['relationships'], true))->count();
    $formAction = $editing ? route('parties.update', $editing['id']) : route('parties.store');
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Business parties</div>
    <h1 class="h3 mb-1">Customers &amp; suppliers</h1>
    <p class="text-muted mb-0">Shared party directory: a business is captured once and granted revocable customer and/or supplier relationships, never duplicated per role.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total parties</div>
            <div class="fs-2 fw-semibold">{{ number_format($snapshot['total_count']) }}</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Active</div>
            <div class="fs-2 fw-semibold">{{ number_format($activeCount) }}</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Customers</div>
            <div class="fs-2 fw-semibold">{{ number_format($customerCount) }}</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Suppliers</div>
            <div class="fs-2 fw-semibold">{{ number_format($supplierCount) }}</div>
        </div></div>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>{{ $editing ? 'Could not update this business party.' : 'Could not save this business party.' }}</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">{{ $editing ? 'Edit business party' : 'Register a business party' }}</div>
                <div class="text-muted small">{{ $editing ? 'Only an active party can be edited.' : 'Select at least one relationship: customer, supplier, or both.' }}</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ $formAction }}">
                    @csrf
                    @if ($editing)
                        @method('PATCH')
                    @endif

                    <div class="mb-3">
                        <label for="display_name" class="form-label">Display name</label>
                        <input type="text" class="form-control" id="display_name" name="display_name" required minlength="2" maxlength="200" value="{{ old('display_name', $editing['display_name'] ?? '') }}">
                    </div>
                    <div class="mb-3">
                        <label for="legal_name" class="form-label">Legal name</label>
                        <input type="text" class="form-control" id="legal_name" name="legal_name" maxlength="200" value="{{ old('legal_name', $editing['legal_name'] ?? '') }}">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="vat_number" class="form-label">VAT number</label>
                            <input type="text" class="form-control" id="vat_number" name="vat_number" maxlength="40" value="{{ old('vat_number', $editing['vat_number'] ?? '') }}">
                        </div>
                        <div class="col-6 mb-3">
                            <label for="tin" class="form-label">TIN</label>
                            <input type="text" class="form-control" id="tin" name="tin" maxlength="40" value="{{ old('tin', $editing['tin'] ?? '') }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="254" value="{{ old('email', $editing['email'] ?? '') }}">
                        </div>
                        <div class="col-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" maxlength="40" value="{{ old('phone', $editing['phone'] ?? '') }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2" maxlength="1000">{{ old('address', $editing['address'] ?? '') }}</textarea>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="form-label h6">Relationship</legend>
                        @php $selected = old('relationships', $editing['relationships'] ?? []); @endphp
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="relationship_customer" name="relationships[]" value="CUSTOMER" @checked(in_array('CUSTOMER', $selected, true))>
                            <label class="form-check-label" for="relationship_customer">Customer</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="relationship_supplier" name="relationships[]" value="SUPPLIER" @checked(in_array('SUPPLIER', $selected, true))>
                            <label class="form-check-label" for="relationship_supplier">Supplier</label>
                        </div>
                    </fieldset>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Save changes' : 'Register party' }}</button>
                        @if ($editing)
                            <a href="{{ route('parties.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Business party register</div>
                <div class="text-muted small">Deactivation preserves all historical records; it never deletes a party.</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Business parties, their contact details, relationships and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Party</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Relationship</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($parties as $party)
                            <tr>
                                <td>
                                    <strong>{{ $party['display_name'] }}</strong>
                                    @if ($party['legal_name'])
                                        <div class="text-muted small">{{ $party['legal_name'] }}</div>
                                    @endif
                                    @if ($party['vat_number'] || $party['tin'])
                                        <div class="text-muted small font-monospace">{{ $party['vat_number'] ?? $party['tin'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($party['email'])
                                        <div class="small">{{ $party['email'] }}</div>
                                    @endif
                                    @if ($party['phone'])
                                        <div class="small">{{ $party['phone'] }}</div>
                                    @endif
                                    @if (! $party['email'] && ! $party['phone'])
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    @foreach ($party['relationships'] as $relationship)
                                        <span class="badge text-bg-light border me-1">{{ ucfirst(strtolower($relationship)) }}</span>
                                    @endforeach
                                </td>
                                <td><x-status-badge :value="$party['status']" type="status" /></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('parties.index', ['edit' => $party['id']]) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        @if ($party['status'] === 'ACTIVE')
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#deactivate-{{ $party['id'] }}" aria-expanded="false" aria-controls="deactivate-{{ $party['id'] }}">Deactivate</button>
                                        @endif
                                    </div>
                                    @if ($party['status'] === 'ACTIVE')
                                        <div class="collapse mt-2" id="deactivate-{{ $party['id'] }}">
                                            <form method="POST" action="{{ route('parties.deactivate', $party['id']) }}" class="d-flex gap-1">
                                                @csrf
                                                <label class="visually-hidden" for="reason-{{ $party['id'] }}">Deactivation reason</label>
                                                <input type="text" class="form-control form-control-sm" id="reason-{{ $party['id'] }}" name="reason" placeholder="Reason (5-500 characters)" minlength="5" maxlength="500" required>
                                                <button type="submit" class="btn btn-sm btn-danger text-nowrap">Confirm</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No business parties on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

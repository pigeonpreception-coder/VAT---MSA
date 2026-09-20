@extends('layouts.app')

@section('title', 'Taxpayer systems')

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Registered domain</div>
    <h1 class="h3 mb-1">Registered taxpayer systems</h1>
    <p class="text-muted mb-0">NamRA e-VAT MS Registered Taxpayer Systems Framework -- a taxpayer's own ERP/POS/accounting/invoicing system, self-registered and approved by NamRA before it can integrate.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@can('permission', 'taxpayer-systems:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Register a system</h2>
            <p class="text-muted small">Identity fields must match your own taxpayer's registered VAT number (and TIN, if provided).</p>
            <form method="POST" action="{{ route('taxpayer-systems.store') }}" class="row g-2">
                @csrf
                <x-idempotency-key />
                <div class="col-md-3">
                    <label for="vat_registration_number" class="form-label small mb-0">VAT registration number</label>
                    <input type="text" id="vat_registration_number" name="vat_registration_number" value="{{ old('vat_registration_number') }}" class="form-control form-control-sm @error('registration') is-invalid @enderror" minlength="1" maxlength="40" required>
                </div>
                <div class="col-md-3">
                    <label for="tin" class="form-label small mb-0">TIN (optional)</label>
                    <input type="text" id="tin" name="tin" value="{{ old('tin') }}" class="form-control form-control-sm" maxlength="40">
                </div>
                <div class="col-md-3">
                    <label for="company_registration_number" class="form-label small mb-0">Company registration number (optional)</label>
                    <input type="text" id="company_registration_number" name="company_registration_number" value="{{ old('company_registration_number') }}" class="form-control form-control-sm" maxlength="40">
                </div>
                <div class="col-md-3">
                    <label for="system_category" class="form-label small mb-0">System category</label>
                    <select id="system_category" name="system_category" class="form-select form-select-sm" required>
                        <option value="ERP" @selected(old('system_category') === 'ERP')>ERP</option>
                        <option value="POS" @selected(old('system_category') === 'POS')>POS</option>
                        <option value="ACCOUNTING" @selected(old('system_category') === 'ACCOUNTING')>Accounting</option>
                        <option value="INVOICING" @selected(old('system_category') === 'INVOICING')>Invoicing</option>
                        <option value="OTHER" @selected(old('system_category') === 'OTHER')>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="system_name" class="form-label small mb-0">System name</label>
                    <input type="text" id="system_name" name="system_name" value="{{ old('system_name') }}" class="form-control form-control-sm" minlength="2" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label for="system_vendor" class="form-label small mb-0">System vendor</label>
                    <input type="text" id="system_vendor" name="system_vendor" value="{{ old('system_vendor') }}" class="form-control form-control-sm" minlength="2" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label for="credential_reference" class="form-label small mb-0">Credential reference (optional)</label>
                    <input type="text" id="credential_reference" name="credential_reference" value="{{ old('credential_reference') }}" class="form-control form-control-sm" minlength="3" maxlength="300">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Register</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        <span class="text-muted small">{{ count($registrations) }} registration{{ count($registrations) === 1 ? '' : 's' }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Registered taxpayer ERP/POS/accounting systems</caption>
            <thead>
                <tr>
                    <th scope="col">System</th>
                    <th scope="col">Category</th>
                    <th scope="col">Status</th>
                    <th scope="col">API status</th>
                    <th scope="col">Security</th>
                    <th scope="col">Last sync</th>
                    @if ($canApprove || $canManage)
                        <th scope="col">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($registrations as $registration)
                    <tr>
                        <td>
                            <strong>{{ $registration['system_name'] }}</strong>
                            <div class="text-muted small">{{ $registration['system_vendor'] }} &middot; {{ $registration['vat_registration_number'] }}</div>
                        </td>
                        <td>{{ $titleCase($registration['system_category']) }}</td>
                        <td><x-status-badge :value="$registration['registration_status']" type="status" /></td>
                        <td><x-status-badge :value="$registration['api_status']" type="status" /></td>
                        <td><x-status-badge :value="$registration['security_status']" type="status" /></td>
                        <td>{{ $registration['last_synchronization_at'] ? \Illuminate\Support\Carbon::parse($registration['last_synchronization_at'])->format('d M Y, H:i') : 'Never' }}</td>
                        @if ($canApprove || $canManage)
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    @if ($canApprove && in_array($registration['registration_status'], ['DRAFT', 'SUSPENDED'], true))
                                        <form method="POST" action="{{ route('taxpayer-systems.approval.store', $registration['id']) }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <button type="submit" class="btn btn-outline-success btn-sm w-100">Approve</button>
                                        </form>
                                    @endif
                                    @if ($canManage && $registration['registration_status'] === 'APPROVED')
                                        <form method="POST" action="{{ route('taxpayer-systems.suspension.store', $registration['id']) }}" class="d-flex gap-1">
                                            @csrf
                                            <x-idempotency-key />
                                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Suspension reason" minlength="10" maxlength="500" required>
                                            <button type="submit" class="btn btn-outline-warning btn-sm text-nowrap">Suspend</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ ($canApprove || $canManage) ? 7 : 6 }}" class="text-center text-muted py-4">No registered taxpayer systems yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

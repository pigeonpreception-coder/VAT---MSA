@extends('layouts.app')

@section('title', 'SaaS providers')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Developer domain</div>
    <h1 class="h3 mb-1">SaaS provider onboarding</h1>
    <p class="text-muted mb-0">Register a SaaS/ERP/accounting integration provider and take it through NamRA's fixed conformance test harness before it may operate against SANDBOX or PRODUCTION data.</p>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@can('permission', 'developer:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Register a provider</h2>
            <p class="text-muted small">Registers the provider and its one named application (the one to be conformance-certified) together.</p>
            <form method="POST" action="{{ route('saas-providers.store') }}" class="row g-2">
                @csrf
                <x-idempotency-key />
                <div class="col-md-3">
                    <label for="provider_key" class="form-label small mb-0">Provider key</label>
                    <input type="text" id="provider_key" name="provider_key" value="{{ old('provider_key') }}" class="form-control form-control-sm" minlength="2" maxlength="50" required>
                </div>
                <div class="col-md-3">
                    <label for="legal_name" class="form-label small mb-0">Legal name</label>
                    <input type="text" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" class="form-control form-control-sm" minlength="3" maxlength="200" required>
                </div>
                <div class="col-md-3">
                    <label for="contact_email" class="form-label small mb-0">Contact email</label>
                    <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email') }}" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label for="category" class="form-label small mb-0">Category</label>
                    <select id="category" name="category" class="form-select form-select-sm" required>
                        <option value="ACCOUNTING" @selected(old('category') === 'ACCOUNTING')>Accounting</option>
                        <option value="ERP" @selected(old('category') === 'ERP')>ERP</option>
                        <option value="PAYROLL" @selected(old('category') === 'PAYROLL')>Payroll</option>
                        <option value="BANKING" @selected(old('category') === 'BANKING')>Banking</option>
                        <option value="LOGISTICS" @selected(old('category') === 'LOGISTICS')>Logistics</option>
                        <option value="OTHER" @selected(old('category') === 'OTHER')>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="application_name" class="form-label small mb-0">Application name</label>
                    <input type="text" id="application_name" name="application_name" value="{{ old('application_name') }}" class="form-control form-control-sm" minlength="3" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label for="endpoint_reference" class="form-label small mb-0">Endpoint reference (https://)</label>
                    <input type="text" id="endpoint_reference" name="endpoint_reference" value="{{ old('endpoint_reference') }}" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4">
                    <label for="requested_capabilities" class="form-label small mb-0">Requested capabilities (comma-separated)</label>
                    <input type="text" id="requested_capabilities" name="requested_capabilities" value="{{ old('requested_capabilities') }}" placeholder="INVOICE_SUBMIT, QUOTATION_READ" class="form-control form-control-sm" required>
                </div>
                <div class="col-12">
                    <label for="application_description" class="form-label small mb-0">Application description</label>
                    <textarea id="application_description" name="application_description" class="form-control form-control-sm" minlength="10" maxlength="2000" rows="2" required>{{ old('application_description') }}</textarea>
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
        <span class="text-muted small">{{ count($providers) }} provider{{ count($providers) === 1 ? '' : 's' }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Registered SaaS providers</caption>
            <thead>
                <tr>
                    <th scope="col">Provider</th>
                    <th scope="col">Category</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Status</th>
                    <th scope="col">Registered</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($providers as $provider)
                    <tr>
                        <td>
                            <strong>{{ $provider['legal_name'] }}</strong>
                            <div class="text-muted small">{{ $provider['provider_key'] }}</div>
                        </td>
                        <td>{{ ucfirst(strtolower($provider['category'])) }}</td>
                        <td>{{ $provider['contact_email'] }}</td>
                        <td><x-status-badge :value="$provider['status']" type="status" /></td>
                        <td>{{ $provider['registered_at'] ? \Illuminate\Support\Carbon::parse($provider['registered_at'])->format('d M Y, H:i') : '—' }}</td>
                        <td><a href="{{ route('saas-providers.show', $provider['id']) }}" class="btn btn-outline-secondary btn-sm">View usage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No SaaS providers registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

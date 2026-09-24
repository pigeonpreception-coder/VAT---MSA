@extends('layouts.app')

@section('title', 'Registration intake')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Controlled onboarding</div>
    <h1 class="h3 mb-1">Taxpayer registration intake</h1>
    <p class="text-muted mb-0">Applications are deduplicated and held for authoritative verification. Submission never creates a taxpayer or organisation automatically.</p>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6">New application</h2>
        @if ($errors->any())
            <div class="alert alert-danger py-2" role="alert">
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('registrations.store') }}" class="row g-2">
            @csrf
            <x-idempotency-key />
            <div class="col-md-3">
                <label for="vat_number" class="form-label small mb-0">VAT number</label>
                <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" maxlength="40" class="form-control form-control-sm @error('vat_number') is-invalid @enderror" required>
                @error('vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="tin" class="form-label small mb-0">TIN</label>
                <input type="text" id="tin" name="tin" value="{{ old('tin') }}" maxlength="40" class="form-control form-control-sm @error('tin') is-invalid @enderror" required>
                @error('tin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="company_registration_number" class="form-label small mb-0">Company registration number</label>
                <input type="text" id="company_registration_number" name="company_registration_number" value="{{ old('company_registration_number') }}" maxlength="40" class="form-control form-control-sm @error('company_registration_number') is-invalid @enderror">
                @error('company_registration_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="legal_name" class="form-label small mb-0">Legal name</label>
                <input type="text" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" maxlength="200" class="form-control form-control-sm @error('legal_name') is-invalid @enderror" required>
                @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="trading_name" class="form-label small mb-0">Trading name</label>
                <input type="text" id="trading_name" name="trading_name" value="{{ old('trading_name') }}" maxlength="200" class="form-control form-control-sm @error('trading_name') is-invalid @enderror">
                @error('trading_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="taxpayer_type" class="form-label small mb-0">Taxpayer type</label>
                <select id="taxpayer_type" name="taxpayer_type" class="form-select form-select-sm @error('taxpayer_type') is-invalid @enderror" required>
                    @foreach (['PRIVATE_COMPANY' => 'Private company', 'CLOSE_CORPORATION' => 'Close corporation', 'SOLE_PROPRIETOR' => 'Sole proprietor', 'PARTNERSHIP' => 'Partnership', 'TRUST' => 'Trust', 'NON_PROFIT' => 'Non-profit', 'PUBLIC_ENTITY' => 'Public entity', 'OTHER' => 'Other'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('taxpayer_type', 'PRIVATE_COMPANY') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('taxpayer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="return_frequency" class="form-label small mb-0">Expected return frequency</label>
                <select id="return_frequency" name="return_frequency" class="form-select form-select-sm @error('return_frequency') is-invalid @enderror" required>
                    @foreach (['MONTHLY' => 'Monthly', 'BIMONTHLY' => 'Bi-monthly', 'QUARTERLY' => 'Quarterly', 'ANNUAL' => 'Annual'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('return_frequency', 'BIMONTHLY') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('return_frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label for="address" class="form-label small mb-0">Registered address</label>
                <textarea id="address" name="address" rows="1" maxlength="500" class="form-control form-control-sm @error('address') is-invalid @enderror" required>{{ old('address') }}</textarea>
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label for="email" class="form-label small mb-0">Official contact email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="254" class="form-control form-control-sm @error('email') is-invalid @enderror" required>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">Submit for verification</button>
            </div>
        </form>
    </div>
</div>

@if ($isNationalScope)
<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Self-serve signup queue</div>
        <div class="text-muted small">{{ count($selfServeApplications) }} pending or historical application{{ count($selfServeApplications) === 1 ? '' : 's' }} &middot; no automatic activation</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Self-serve commercial SaaS signup applications</caption>
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Applicant</th>
                    <th scope="col">Organisation</th>
                    <th scope="col">VAT / TIN</th>
                    <th scope="col">Requested plan</th>
                    <th scope="col">Identity</th>
                    <th scope="col">Taxpayer verification</th>
                    <th scope="col">Licence</th>
                    <th scope="col">Conflict</th>
                    <th scope="col">Submitted</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($selfServeApplications as $application)
                    <tr>
                        <td class="font-monospace">
                            <strong>{{ $application['public_reference'] }}</strong>
                            <div><x-status-badge :value="$application['status']" /></div>
                        </td>
                        <td>
                            <strong>{{ $application['applicant_name'] }}</strong>
                            <div class="text-muted small">{{ str_replace('_', ' ', $application['applicant_role']) }} &middot; {{ $application['contact_email'] }}</div>
                        </td>
                        <td>{{ $application['legal_name'] }}</td>
                        <td>
                            <span class="font-monospace">{{ $application['vat_number'] }}</span>
                            <div class="font-monospace text-muted small">{{ $application['tin'] }}</div>
                        </td>
                        <td>
                            {{ $application['plan_name'] }}
                            <div class="font-monospace text-muted small">{{ $application['plan_code'] }}</div>
                        </td>
                        <td><x-status-badge :value="$application['identity_status']" /></td>
                        <td><x-status-badge :value="$application['taxpayer_verification_status']" /></td>
                        <td><x-status-badge :value="$application['licence_status']" /></td>
                        <td>
                            @if ($application['identity_conflict_detected'])
                                <span class="badge text-bg-danger">Conflict detected</span>
                            @else
                                <span class="badge text-bg-success">Clear</span>
                            @endif
                        </td>
                        <td>{{ optional($application['submitted_at'])->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No self-serve applications. Public submissions will appear here after validation and deduplication.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Registration applications</div>
        <div class="text-muted small">{{ count($applications) }} controlled application{{ count($applications) === 1 ? '' : 's' }}</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Controlled taxpayer registration applications and their ITAS/NamRA verification and identity-proofing status</caption>
            <thead>
                <tr>
                    <th scope="col">Applicant</th>
                    <th scope="col">VAT number</th>
                    <th scope="col">TIN</th>
                    <th scope="col">Type</th>
                    <th scope="col">Provider verification</th>
                    <th scope="col">Identity proofing</th>
                    <th scope="col" class="text-end">Confidence</th>
                    <th scope="col">Mismatch</th>
                    <th scope="col">Submitted</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($applications as $application)
                    <tr>
                        <td>
                            <strong>{{ $application['legal_name'] }}</strong>
                            <div class="text-muted small">{{ $application['email'] }}</div>
                        </td>
                        <td class="font-monospace">{{ $application['vat_number'] }}</td>
                        <td class="font-monospace">{{ $application['tin'] }}</td>
                        <td>{{ str_replace('_', ' ', $application['taxpayer_type']) }}</td>
                        <td><x-status-badge :value="$application['verification_status'] ?? 'NOT_STARTED'" /></td>
                        <td>
                            <x-status-badge :value="$application['proofing_status'] ?? 'NOT_STARTED'" />
                            <div class="text-muted small">{{ str_replace('_', ' ', $application['proofing_reason_code'] ?? 'No proofing case') }}</div>
                        </td>
                        <td class="text-end">{{ $application['proofing_confidence_bps'] === null ? '—' : number_format($application['proofing_confidence_bps'] / 100, 2).'%' }}</td>
                        <td><x-status-badge :value="$application['mismatch_status'] ?? 'NONE'" /></td>
                        <td>{{ optional($application['submitted_at'])->format('Y-m-d H:i') }}</td>
                        <td><x-status-badge :value="$application['status']" /></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No registration applications have been submitted.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

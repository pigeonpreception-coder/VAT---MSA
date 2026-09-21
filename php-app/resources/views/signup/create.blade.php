@extends('layouts.app')

@section('title', 'Apply for a commercial subscription')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <div class="mb-4">
            <div class="text-uppercase text-muted small fw-semibold">Self-serve signup</div>
            <h1 class="h3 mb-1">Apply for a commercial VAT-MSA subscription</h1>
            <p class="text-muted mb-0">This starts a controlled application, held for administrator and organisation verification. No account, payment, subscription or licence is activated by submitting this form.</p>
        </div>

        @if (session('accepted'))
            @php($accepted = session('accepted'))
            <div class="alert alert-success" role="status">
                <p class="mb-1"><strong>Application submitted.</strong> Reference: <strong>{{ $accepted['application_reference'] }}</strong></p>
                <p class="mb-0 small">{{ $accepted['next_action'] }}</p>
            </div>
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

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('signup.store') }}" class="row g-2">
                    @csrf
                    <x-idempotency-key />

                    <div class="col-12"><h2 class="h6 mb-0">Applicant</h2></div>
                    <div class="col-md-6">
                        <label for="applicant_name" class="form-label small mb-0">Your full name</label>
                        <input type="text" id="applicant_name" name="applicant_name" value="{{ old('applicant_name') }}" class="form-control form-control-sm" minlength="2" maxlength="120" required>
                    </div>
                    <div class="col-md-3">
                        <label for="applicant_role" class="form-label small mb-0">Your authority</label>
                        <select id="applicant_role" name="applicant_role" class="form-select form-select-sm" required>
                            <option value="OWNER" @selected(old('applicant_role') === 'OWNER')>Owner</option>
                            <option value="DIRECTOR" @selected(old('applicant_role') === 'DIRECTOR')>Director</option>
                            <option value="PARTNER" @selected(old('applicant_role') === 'PARTNER')>Partner</option>
                            <option value="TRUSTEE" @selected(old('applicant_role') === 'TRUSTEE')>Trustee</option>
                            <option value="AUTHORISED_REPRESENTATIVE" @selected(old('applicant_role') === 'AUTHORISED_REPRESENTATIVE')>Authorised representative</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="contact_email" class="form-label small mb-0">Contact email</label>
                        <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email') }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label for="plan_code" class="form-label small mb-0">Licence plan code</label>
                        <input type="text" id="plan_code" name="plan_code" value="{{ old('plan_code', 'PILOT_PROFESSIONAL') }}" class="form-control form-control-sm" required>
                    </div>

                    <div class="col-12 mt-3"><h2 class="h6 mb-0">Company</h2></div>
                    <div class="col-md-3">
                        <label for="vat_number" class="form-label small mb-0">VAT number</label>
                        <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label for="tin" class="form-label small mb-0">TIN</label>
                        <input type="text" id="tin" name="tin" value="{{ old('tin') }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label for="company_registration_number" class="form-label small mb-0">Company registration number (optional)</label>
                        <input type="text" id="company_registration_number" name="company_registration_number" value="{{ old('company_registration_number') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label for="taxpayer_type" class="form-label small mb-0">Taxpayer type</label>
                        <select id="taxpayer_type" name="taxpayer_type" class="form-select form-select-sm" required>
                            <option value="PRIVATE_COMPANY">Private company</option>
                            <option value="CLOSE_CORPORATION">Close corporation</option>
                            <option value="SOLE_PROPRIETOR">Sole proprietor</option>
                            <option value="PARTNERSHIP">Partnership</option>
                            <option value="TRUST">Trust</option>
                            <option value="NON_PROFIT">Non-profit</option>
                            <option value="PUBLIC_ENTITY">Public entity</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="legal_name" class="form-label small mb-0">Legal name</label>
                        <input type="text" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" class="form-control form-control-sm" minlength="2" maxlength="200" required>
                    </div>
                    <div class="col-md-6">
                        <label for="trading_name" class="form-label small mb-0">Trading name (optional)</label>
                        <input type="text" id="trading_name" name="trading_name" value="{{ old('trading_name') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label for="return_frequency" class="form-label small mb-0">Return frequency</label>
                        <select id="return_frequency" name="return_frequency" class="form-select form-select-sm" required>
                            <option value="MONTHLY">Monthly</option>
                            <option value="BIMONTHLY">Bi-monthly</option>
                            <option value="QUARTERLY">Quarterly</option>
                            <option value="ANNUAL">Annual</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="address" class="form-label small mb-0">Address</label>
                        <input type="text" id="address" name="address" value="{{ old('address') }}" class="form-control form-control-sm" minlength="5" maxlength="500" required>
                    </div>

                    <div class="col-12 mt-3">
                        <div class="form-check">
                            <input type="checkbox" id="company_system_administrator_attested" name="company_system_administrator_attested" value="1" class="form-check-input" required>
                            <label for="company_system_administrator_attested" class="form-check-label small">I am the verified Company System Administrator for this organisation.</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" id="terms_accepted" name="terms_accepted" value="1" class="form-check-input" required>
                            <label for="terms_accepted" class="form-check-label small">I accept the current terms.</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" id="privacy_notice_accepted" name="privacy_notice_accepted" value="1" class="form-check-input" required>
                            <label for="privacy_notice_accepted" class="form-check-label small">I acknowledge the current privacy notice.</label>
                        </div>
                    </div>
                    <div class="col-md-3 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Submit application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

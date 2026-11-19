@extends('layouts.app')

@section('title', $party['display_name'])

@section('content')
<x-page-header :title="$party['display_name']" :back="route('business-parties.index')" back-label="Customers and suppliers">
    <x-slot:meta>
        <p class="page-subtitle">
            {{ $party['legal_name'] }}
            @if ($party['vat_number'])
                &middot; VAT {{ $party['vat_number'] }}
            @endif
            @foreach ($party['relationships'] as $relationship)
                <span class="badge text-bg-light border ms-1">@humanize($relationship)</span>
            @endforeach
        </p>
    </x-slot:meta>
    <x-slot:actions><x-status-badge :value="$party['status']" type="status" /></x-slot:actions>
</x-page-header>

<x-form-errors />

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Verification history</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Supplier verification snapshots, most recent first</caption>
                    <thead>
                        <tr>
                            <th scope="col">Verified</th>
                            <th scope="col">Taxpayer active</th>
                            <th scope="col">Organisation active</th>
                            <th scope="col">Can act as seller</th>
                            <th scope="col">Capabilities</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshots as $snapshot)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($snapshot['verified_at'])->format('d M Y H:i') }}</td>
                                <td>{!! $snapshot['taxpayer_active'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' !!}</td>
                                <td>{!! $snapshot['organisation_active'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' !!}</td>
                                <td>{!! $snapshot['can_act_as_seller'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' !!}</td>
                                <td>{{ empty($snapshot['capabilities']) ? '—' : implode(', ', $snapshot['capabilities']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Not yet verified.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('permission', 'parties:manage')
                <div class="card-footer">
                    @php $canVerify = $party['vat_number'] && in_array('SUPPLIER', $party['relationships'], true); @endphp
                    <form method="POST" action="{{ route('business-parties.verification.store', $party['id']) }}" class="d-inline">
                        @csrf
                    <x-idempotency-key />
                        <button type="submit" class="btn btn-primary btn-sm" @disabled(! $canVerify)>Verify against national taxpayer register</button>
                        @unless ($canVerify)
                            <span class="text-muted small ms-2">
                                @unless (in_array('SUPPLIER', $party['relationships'], true))
                                    Only a supplier relationship can be verified.
                                @else
                                    Add a VAT number to this party before it can be verified.
                                @endunless
                            </span>
                        @endunless
                    </form>
                </div>
            @endcan
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Contact details</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">TIN</span><span>{{ $party['tin'] ?? '—' }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Company reg. no.</span><span>{{ $party['company_registration_number'] ?? '—' }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Email</span><span>{{ $party['email'] ?? '—' }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Phone</span><span>{{ $party['phone'] ?? '—' }}</span></li>
                <li class="list-group-item"><span class="text-muted d-block">Address</span>{{ $party['address'] ?? '—' }}</li>
            </ul>
        </div>

        <div class="card mb-3">
            <div class="card-header">Counterparty trust</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Trust status</span><span class="badge text-bg-light border">@humanize($party['trust_status'] ?? 'PENDING_PROVIDER')</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Tax registration</span><span>@humanize($party['tax_registration_status'] ?? 'UNKNOWN')</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Confidence</span><span>{{ $party['confidence_bps'] === null ? 'No evidence' : number_format($party['confidence_bps'] / 100, 2).'%' }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Provider environment</span><span>@humanize($party['provider_environment'] ?? 'UNKNOWN')</span></li>
                @if ($party['expires_at'])
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Expires</span><span>{{ \Illuminate\Support\Carbon::parse($party['expires_at'])->format('d M Y H:i') }}</span></li>
                @endif
            </ul>
            <p class="text-muted small px-3 pt-2 mb-0">New quotations, projects and expenses with VAT need a party with a current verification, not just an active relationship. Verification against {{ $tenantAuthorityShortName }}'s official registers isn't connected yet.</p>
            @can('permission', 'parties:manage')
                @if ($syntheticVerificationEnabled && $party['status'] === 'ACTIVE')
                    <div class="card-footer">
                        <p class="text-muted small">Test environments only: records clearly labelled test evidence to try the verification workflow. It doesn't prove the party exists. Leave a field as it is to match the party's details, or change it to simulate a mismatch.</p>
                        <form method="POST" action="{{ route('business-parties.synthetic-verification.store', $party['id']) }}">
                            @csrf
                            <x-idempotency-key />
                            <div class="mb-2">
                                <label for="authority_legal_name" class="form-label small mb-0">Authority legal name</label>
                                <input type="text" id="authority_legal_name" name="authority_legal_name" value="{{ old('authority_legal_name', $party['legal_name'] ?? $party['display_name']) }}" required class="form-control form-control-sm @error('authority_legal_name') is-invalid @enderror">
                                @error('authority_legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-2">
                                <label for="authority_vat_number" class="form-label small mb-0">Authority VAT number</label>
                                <input type="text" id="authority_vat_number" name="authority_vat_number" value="{{ old('authority_vat_number', $party['vat_number']) }}" class="form-control form-control-sm @error('authority_vat_number') is-invalid @enderror">
                                @error('authority_vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-2">
                                <label for="authority_tin" class="form-label small mb-0">Authority TIN</label>
                                <input type="text" id="authority_tin" name="authority_tin" value="{{ old('authority_tin', $party['tin']) }}" class="form-control form-control-sm @error('authority_tin') is-invalid @enderror">
                                @error('authority_tin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-2">
                                <label for="authority_company_registration_number" class="form-label small mb-0">Authority company reg. no.</label>
                                <input type="text" id="authority_company_registration_number" name="authority_company_registration_number" value="{{ old('authority_company_registration_number', $party['company_registration_number']) }}" class="form-control form-control-sm @error('authority_company_registration_number') is-invalid @enderror">
                                @error('authority_company_registration_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-2">
                                <label for="authority_tax_registration_status" class="form-label small mb-0">Authority tax-registration status</label>
                                <select id="authority_tax_registration_status" name="authority_tax_registration_status" class="form-select form-select-sm @error('authority_tax_registration_status') is-invalid @enderror">
                                    @foreach (['ACTIVE', 'INACTIVE', 'SUSPENDED', 'CANCELLED', 'NOT_REGISTERED'] as $status)
                                        <option value="{{ $status }}" @selected(old('authority_tax_registration_status', 'ACTIVE') === $status)>@humanize($status)</option>
                                    @endforeach
                                </select>
                                @error('authority_tax_registration_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">Run synthetic trust check</button>
                        </form>
                    </div>
                @endif
            @endcan
        </div>

        @can('permission', 'parties:manage')
            @if ($party['status'] === 'ACTIVE')
                <div class="card border-danger">
                    <div class="card-header text-danger">Deactivate</div>
                    <div class="card-body">
                        <p class="text-muted small">Deactivating preserves all history but stops this party from being used in new transactions.</p>
                        <form method="POST" action="{{ route('business-parties.deactivation.store', $party['id']) }}">
                            @csrf
                    <x-idempotency-key />
                            <div class="mb-2">
                                <label for="reason" class="form-label small mb-0">Reason</label>
                                <textarea id="reason" name="reason" minlength="5" maxlength="500" rows="2" required class="form-control form-control-sm @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">Deactivate party</button>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>
@endsection

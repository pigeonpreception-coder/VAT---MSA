@extends('layouts.app')

@section('title', 'Audit cases')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain</div>
    <h1 class="h3 mb-1">Audit cases</h1>
    <p class="text-muted mb-0">A fully governed audit lifecycle -- every status change, finding, evidence citation and note is recorded, never edited or deleted in place.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@can('permission', 'cases:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Open a case</h2>
            @if ($errors->any())
                <div class="alert alert-danger py-2" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('audit-cases.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label for="vat_number" class="form-label small mb-0">Taxpayer VAT number</label>
                    <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control form-control-sm @error('vat_number') is-invalid @enderror" required>
                    @error('vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="case_type" class="form-label small mb-0">Case type</label>
                    <select id="case_type" name="case_type" class="form-select form-select-sm @error('case_type') is-invalid @enderror" required>
                        <option value="DESK_REVIEW" @selected(old('case_type') === 'DESK_REVIEW')>Desk review</option>
                        <option value="VAT_AUDIT" @selected(old('case_type') === 'VAT_AUDIT')>VAT audit</option>
                        <option value="REFUND_VERIFICATION" @selected(old('case_type') === 'REFUND_VERIFICATION')>Refund verification</option>
                        <option value="INVESTIGATION" @selected(old('case_type') === 'INVESTIGATION')>Investigation</option>
                    </select>
                    @error('case_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="risk_tier" class="form-label small mb-0">Risk tier</label>
                    <select id="risk_tier" name="risk_tier" class="form-select form-select-sm @error('risk_tier') is-invalid @enderror" required>
                        <option value="LOW" @selected(old('risk_tier') === 'LOW')>Low</option>
                        <option value="MEDIUM" @selected(old('risk_tier') === 'MEDIUM')>Medium</option>
                        <option value="HIGH" @selected(old('risk_tier') === 'HIGH')>High</option>
                        <option value="CRITICAL" @selected(old('risk_tier') === 'CRITICAL')>Critical</option>
                    </select>
                    @error('risk_tier')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="title" class="form-label small mb-0">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}" class="form-control form-control-sm @error('title') is-invalid @enderror" minlength="5" maxlength="200" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2 align-self-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Open case</button>
                </div>
                <div class="col-12">
                    <label for="opening_reason" class="form-label small mb-0">Opening reason</label>
                    <textarea id="opening_reason" name="opening_reason" class="form-control form-control-sm @error('opening_reason') is-invalid @enderror" minlength="20" maxlength="2000" rows="2" required>{{ old('opening_reason') }}</textarea>
                    @error('opening_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('audit-cases.index') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($status === '')>All statuses</option>
                    @foreach (['PROPOSED', 'AUTHORIZED', 'ASSIGNED', 'PLANNING', 'EVIDENCE_COLLECTION', 'ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW', 'DECISION', 'SUSPENDED', 'CLOSED', 'CANCELLED'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(strtolower(str_replace('_', ' ', $s))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8 text-md-end small text-muted">{{ count($cases) }} case{{ count($cases) === 1 ? '' : 's' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Audit cases, filterable by status</caption>
            <thead>
                <tr>
                    <th scope="col">Case</th>
                    <th scope="col">Taxpayer</th>
                    <th scope="col">Type</th>
                    <th scope="col">Risk</th>
                    <th scope="col">Status</th>
                    <th scope="col">Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cases as $case)
                    <tr>
                        <td>
                            <a href="{{ route('audit-cases.show', $case['id']) }}"><strong>{{ $case['case_number'] }}</strong></a>
                            <div class="text-muted small">{{ $case['title'] }}</div>
                        </td>
                        <td>
                            {{ $case['legal_name'] ?? '—' }}
                            <div class="text-muted small">{{ $case['vat_number'] ?? '' }}</div>
                        </td>
                        <td>{{ ucwords(strtolower(str_replace('_', ' ', $case['case_type']))) }}</td>
                        <td><x-status-badge :value="$case['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$case['status']" type="status" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($case['updated_at'])->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No audit cases match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

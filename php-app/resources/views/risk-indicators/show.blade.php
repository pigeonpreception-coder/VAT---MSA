@extends('layouts.app')

@section('title', ucwords(strtolower(str_replace('_', ' ', $indicator->indicator_code))))

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y, H:i') : '—';
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Risk indicator &middot; NamRA-restricted</div>
        <h1 class="h3 mb-1">{{ $titleCase($indicator->indicator_code) }}</h1>
        <p class="text-muted mb-0">{{ $taxpayer->legal_name ?? 'Unknown subject' }} @if ($taxpayer) &middot; {{ $taxpayer->vat_number }} @endif</p>
    </div>
    <a href="{{ route('risk-indicators.index') }}" class="btn btn-outline-secondary align-self-center">&larr; Back to risk indicators</a>
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

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">Indicator record</div>
        <x-status-badge :value="$indicator->status" type="indicator" />
    </div>
    <dl class="card-body row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-0">
        <div class="col"><dt class="text-muted small">Severity</dt><dd class="mb-0"><x-status-badge :value="$indicator->severity" type="risk" /></dd></div>
        <div class="col"><dt class="text-muted small">Score</dt><dd class="mb-0 fw-semibold">{{ number_format($indicator->score_bps / 100, 1) }}%</dd></div>
        <div class="col"><dt class="text-muted small">Decision effect</dt><dd class="mb-0 fw-semibold">{{ $titleCase($indicator->decision_effect) }}</dd></div>
        <div class="col"><dt class="text-muted small">Rule version</dt><dd class="mb-0 fw-semibold font-monospace small">{{ $indicator->rule_version }}</dd></div>
        <div class="col"><dt class="text-muted small">Detected</dt><dd class="mb-0 fw-semibold">{{ $dateTime($indicator->detected_at) }}</dd></div>
        <div class="col"><dt class="text-muted small">Reviewed</dt><dd class="mb-0 fw-semibold">{{ $dateTime($indicator->reviewed_at) }}</dd></div>
        <div class="col col-lg-6"><dt class="text-muted small">Rationale</dt><dd class="mb-0">{{ $indicator->rationale }}</dd></div>
        @if ($indicator->escalated_case_id)
            <div class="col"><dt class="text-muted small">Escalated case</dt><dd class="mb-0 fw-semibold">{{ $indicator->escalatedCase?->case_number ?? $indicator->escalated_case_id }}</dd></div>
        @endif
    </dl>
</div>

@can('permission', 'risk:review')
    @if ($indicator->status === 'OPEN')
        <div class="card mt-3">
            <div class="card-body">
                <h2 class="h6">Assign for review</h2>
                <p class="text-muted small">Moves this indicator to Under Review under a named officer's own accountability.</p>
                <form method="POST" action="{{ route('risk-indicators.assignment.store', $indicator->id) }}" class="row g-2">
                    @csrf
                    <div class="col-md-6">
                        <label for="officer_id" class="form-label">Officer</label>
                        <select id="officer_id" name="officer_id" class="form-select @error('officer_id') is-invalid @enderror" required>
                            <option value="">Select an officer</option>
                            @foreach ($officers as $officer)
                                <option value="{{ $officer->id }}" @selected(old('officer_id') === $officer->id)>{{ $officer->name }} ({{ $officer->role }})</option>
                            @endforeach
                        </select>
                        @error('officer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-auto align-self-end">
                        <button type="submit" class="btn btn-primary btn-sm">Assign review</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($indicator->status === 'UNDER_REVIEW')
        <div class="card mt-3">
            <div class="card-body">
                <h2 class="h6">Record a decision</h2>
                <p class="text-muted small">Dismiss the signal, or escalate it into a governed audit case -- the case's risk tier and opening reason are taken from this indicator's own severity and your rationale, never entered independently.</p>
                <form method="POST" action="{{ route('risk-indicators.decision.store', $indicator->id) }}" id="decision-form">
                    @csrf
                    <div class="mb-2">
                        <label for="decision" class="form-label">Decision</label>
                        <select id="decision" name="decision" class="form-select @error('decision') is-invalid @enderror" required onchange="document.getElementById('case-fields').hidden = this.value !== 'ESCALATE_TO_CASE'">
                            <option value="DISMISS" @selected(old('decision') === 'DISMISS')>Dismiss</option>
                            <option value="ESCALATE_TO_CASE" @selected(old('decision') === 'ESCALATE_TO_CASE')>Escalate to case</option>
                        </select>
                        @error('decision')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-2">
                        <label for="rationale" class="form-label">Rationale</label>
                        <textarea id="rationale" name="rationale" class="form-control @error('rationale') is-invalid @enderror" minlength="20" maxlength="2000" rows="2" required>{{ old('rationale') }}</textarea>
                        @error('rationale')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div id="case-fields" @if (old('decision') !== 'ESCALATE_TO_CASE') hidden @endif>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label for="case_type" class="form-label">Case type</label>
                                <select id="case_type" name="case_type" class="form-select @error('case_type') is-invalid @enderror">
                                    <option value="DESK_REVIEW" @selected(old('case_type') === 'DESK_REVIEW')>Desk review</option>
                                    <option value="VAT_AUDIT" @selected(old('case_type') === 'VAT_AUDIT')>VAT audit</option>
                                    <option value="REFUND_VERIFICATION" @selected(old('case_type') === 'REFUND_VERIFICATION')>Refund verification</option>
                                    <option value="INVESTIGATION" @selected(old('case_type') === 'INVESTIGATION')>Investigation</option>
                                </select>
                                @error('case_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-8">
                                <label for="case_title" class="form-label">Case title</label>
                                <input type="text" id="case_title" name="case_title" value="{{ old('case_title') }}" class="form-control @error('case_title') is-invalid @enderror" minlength="5" maxlength="200">
                                @error('case_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Submit decision</button>
                </form>
            </div>
        </div>
    @endif
@endcan
@endsection

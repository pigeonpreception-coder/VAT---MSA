@extends('layouts.app')

@section('title', $case->case_number)

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y, H:i') : '—';
    $money = fn (int $cents, string $currency = 'NAD') => $currency.' '.number_format($cents / 100, 2);
    $actorName = fn (?string $id) => $id ? ($actorNames[$id] ?? $id) : '—';
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Audit case</div>
        <h1 class="h3 mb-1">{{ $case->case_number }}</h1>
        <p class="text-muted mb-0">{{ $case->title }} @if ($taxpayer) &middot; {{ $taxpayer->legal_name }} ({{ $taxpayer->vat_number }}) @endif</p>
    </div>
    <a href="{{ route('audit-cases.index') }}" class="btn btn-outline-secondary align-self-center">&larr; Back to audit cases</a>
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
        <div class="fw-semibold">Case record</div>
        <x-status-badge :value="$case->status" type="status" />
    </div>
    <dl class="card-body row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-0">
        <div class="col"><dt class="text-muted small">Case type</dt><dd class="mb-0 fw-semibold">{{ $titleCase($case->case_type) }}</dd></div>
        <div class="col"><dt class="text-muted small">Risk tier</dt><dd class="mb-0"><x-status-badge :value="$case->risk_tier" type="risk" /></dd></div>
        <div class="col"><dt class="text-muted small">Assigned officer</dt><dd class="mb-0 fw-semibold">{{ $actorName($case->assigned_officer_id) }}</dd></div>
        <div class="col"><dt class="text-muted small">Opened by</dt><dd class="mb-0 fw-semibold">{{ $actorName($case->opened_by) }}</dd></div>
        <div class="col"><dt class="text-muted small">Opened</dt><dd class="mb-0 fw-semibold">{{ $dateTime($case->opened_at) }}</dd></div>
        <div class="col"><dt class="text-muted small">Closed</dt><dd class="mb-0 fw-semibold">{{ $dateTime($case->closed_at) }}</dd></div>
        @if ($case->suspended_from_status)
            <div class="col"><dt class="text-muted small">Suspended from</dt><dd class="mb-0 fw-semibold">{{ $titleCase($case->suspended_from_status) }}</dd></div>
        @endif
        @if ($case->appeal_reference)
            <div class="col"><dt class="text-muted small">Appeal reference</dt><dd class="mb-0 fw-semibold">{{ $case->appeal_reference }} <span class="text-muted small">({{ $dateTime($case->appeal_linked_at) }})</span></dd></div>
        @endif
        <div class="col col-lg-9"><dt class="text-muted small">Opening reason</dt><dd class="mb-0">{{ $case->opening_reason }}</dd></div>
    </dl>
</div>

@can('permission', 'cases:manage')
    @if (count($validActions))
        <div class="card mt-3">
            <div class="card-body">
                <h2 class="h6">Record a decision</h2>
                <form method="POST" action="{{ route('audit-cases.transition.store', $case->id) }}" id="transition-form">
                    @csrf
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="action" class="form-label">Action</label>
                            <select id="action" name="action" class="form-select @error('action') is-invalid @enderror" required
                                onchange="document.getElementById('officer-field').hidden = this.value !== 'ASSIGN';
                                          document.getElementById('appeal-field').hidden = this.value !== 'LINK_APPEAL';
                                          document.getElementById('override-field').hidden = this.value !== 'CLOSE';">
                                @foreach ($validActions as $action)
                                    <option value="{{ $action }}" @selected(old('action') === $action)>{{ $titleCase($action) }}</option>
                                @endforeach
                            </select>
                            @error('action')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-9">
                            <label for="reason" class="form-label">Reason</label>
                            <textarea id="reason" name="reason" class="form-control @error('reason') is-invalid @enderror" minlength="10" maxlength="2000" rows="1" required>{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4" id="officer-field" @if (old('action') !== 'ASSIGN') hidden @endif>
                            <label for="officer_id" class="form-label">Officer</label>
                            <select id="officer_id" name="officer_id" class="form-select @error('officer_id') is-invalid @enderror">
                                <option value="">Select an officer</option>
                                @foreach ($officers as $officer)
                                    <option value="{{ $officer->id }}" @selected(old('officer_id') === $officer->id)>{{ $officer->name }} ({{ $officer->role }})</option>
                                @endforeach
                            </select>
                            @error('officer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4" id="appeal-field" @if (old('action') !== 'LINK_APPEAL') hidden @endif>
                            <label for="appeal_reference" class="form-label">Appeal reference</label>
                            <input type="text" id="appeal_reference" name="appeal_reference" value="{{ old('appeal_reference') }}" class="form-control @error('appeal_reference') is-invalid @enderror">
                            @error('appeal_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @if ($requiresSodOverride)
                            <div class="col-md-6" id="override-field" @if (old('action') !== 'CLOSE') hidden @endif>
                                <label for="override_reason" class="form-label">Segregation-of-duties override reason</label>
                                <input type="text" id="override_reason" name="override_reason" value="{{ old('override_reason') }}" class="form-control @error('override_reason') is-invalid @enderror" minlength="10" maxlength="2000"
                                    @if (! $canOverrideSod) disabled placeholder="Requires cases:override-sod -- you opened this case" @endif>
                                @error('override_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">You opened this case -- closing it requires an authorised supervisor's override reason.</div>
                            </div>
                        @else
                            <div id="override-field" hidden></div>
                        @endif
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Submit decision</button>
                </form>
            </div>
        </div>
    @endif
@endcan

<div class="card mt-3">
    <div class="card-header"><div class="fw-semibold">Timeline</div></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Every status change recorded against this case</caption>
            <thead><tr><th scope="col">Action</th><th scope="col">From</th><th scope="col">To</th><th scope="col">By</th><th scope="col">When</th><th scope="col">Reason</th></tr></thead>
            <tbody>
                @forelse ($transitions as $t)
                    <tr>
                        <td>{{ $titleCase($t['action']) }}</td>
                        <td><x-status-badge :value="$t['from_status']" type="status" /></td>
                        <td><x-status-badge :value="$t['to_status']" type="status" /></td>
                        <td>{{ $actorName($t['actor_id']) }}</td>
                        <td>{{ $dateTime($t['occurred_at']) }}</td>
                        <td class="text-muted small">{{ $t['reason'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No transitions recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><div class="fw-semibold">Findings</div></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Findings issued against this case</caption>
            <thead><tr><th scope="col">Code</th><th scope="col">Title</th><th scope="col" class="text-end">Amount</th><th scope="col">Status</th><th scope="col">Author</th></tr></thead>
            <tbody>
                @forelse ($findings as $finding)
                    <tr>
                        <td class="font-monospace small">{{ $finding->finding_code }}</td>
                        <td>{{ $finding->title }}<div class="text-muted small">{{ $finding->description }}</div></td>
                        <td class="text-end">{{ $money((int) $finding->amount_cents, $finding->currency) }}</td>
                        <td><x-status-badge :value="$finding->status" type="status" /></td>
                        <td>{{ $actorName($finding->author_id) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No findings issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @can('permission', 'cases:manage')
        @if (in_array($case->status, ['ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW'], true))
            <div class="card-footer bg-transparent">
                <h2 class="h6">Issue a finding</h2>
                <form method="POST" action="{{ route('audit-cases.findings.store', $case->id) }}" class="row g-2">
                    @csrf
                    <div class="col-md-2">
                        <label for="finding_code" class="form-label small mb-0">Code</label>
                        <input type="text" id="finding_code" name="finding_code" value="{{ old('finding_code') }}" class="form-control form-control-sm @error('finding_code') is-invalid @enderror" required>
                        @error('finding_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="finding-title" class="form-label small mb-0">Title</label>
                        <input type="text" id="finding-title" name="title" value="{{ old('title') }}" class="form-control form-control-sm @error('title') is-invalid @enderror" minlength="5" maxlength="200" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label for="finding-amount" class="form-label small mb-0">Amount (NAD)</label>
                        <input type="number" step="0.01" min="0" id="finding-amount" name="amount" value="{{ old('amount') }}" class="form-control form-control-sm @error('amount_cents') is-invalid @enderror" required>
                        @error('amount_cents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label for="legal_reference" class="form-label small mb-0">Legal reference</label>
                        <input type="text" id="legal_reference" name="legal_reference" value="{{ old('legal_reference') }}" class="form-control form-control-sm">
                    </div>
                    @if ($requiresSodOverride)
                        <div class="col-md-3">
                            <label for="finding-override" class="form-label small mb-0">SoD override reason</label>
                            <input type="text" id="finding-override" name="override_reason" value="{{ old('override_reason') }}" class="form-control form-control-sm @error('override_reason') is-invalid @enderror"
                                @if (! $canOverrideSod) disabled placeholder="Requires cases:override-sod" @endif>
                            @error('override_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    <div class="col-12">
                        <label for="finding-description" class="form-label small mb-0">Description</label>
                        <textarea id="finding-description" name="description" class="form-control form-control-sm @error('description') is-invalid @enderror" minlength="20" maxlength="4000" rows="2" required>{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Issue finding</button>
                    </div>
                </form>
            </div>
        @endif
    @endcan
</div>

<div class="card mt-3">
    <div class="card-header"><div class="fw-semibold">Evidence</div><div class="text-muted small">Preserved with a checksum at citation time; superseding replaces the active citation without deleting the prior one</div></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Evidence cited against this case</caption>
            <thead><tr><th scope="col">Source</th><th scope="col">Checksum</th><th scope="col">Status</th><th scope="col">Legal hold</th><th scope="col">Added</th></tr></thead>
            <tbody>
                @forelse ($evidence as $e)
                    <tr>
                        <td>
                            {{ $titleCase($e['source_resource_type']) }}
                            <div class="text-muted small font-monospace">{{ $e['source_resource_id'] }}</div>
                            <div class="text-muted small">{{ $e['description'] }}</div>
                        </td>
                        <td class="font-monospace small text-break">{{ mb_substr($e['checksum_sha256'], 0, 16) }}&hellip;</td>
                        <td><x-status-badge :value="$e['status']" type="status" /></td>
                        <td>{{ $e['legal_hold'] ? 'Yes' : 'No' }}</td>
                        <td>{{ $actorName($e['added_by']) }}<div class="text-muted small">{{ $dateTime($e['added_at']) }}</div></td>
                    </tr>
                    @can('permission', 'cases:manage')
                        <tr>
                            <td colspan="5" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('audit-evidence.custody-events.store', $e['id']) }}" class="row g-2 align-items-end py-1">
                                    @csrf
                                    <div class="col-md-3">
                                        <label class="form-label small mb-0">Custody action</label>
                                        <select name="action" class="form-select form-select-sm">
                                            <option value="VERIFY">Verify integrity</option>
                                            <option value="SET_LEGAL_HOLD">Set legal hold</option>
                                            <option value="RELEASE_LEGAL_HOLD">Release legal hold</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-0">Notes</label>
                                        <input type="text" name="notes" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">Record</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endcan
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No evidence cited yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if (count($custodyEvents))
        <div class="card-footer bg-transparent">
            <h2 class="h6">Custody history</h2>
            <ul class="list-unstyled small mb-0">
                @foreach ($custodyEvents as $c)
                    <li class="mb-1">
                        <strong>{{ $titleCase($c['action']) }}</strong> by {{ $actorName($c['actor_id']) }} on {{ $dateTime($c['occurred_at']) }}
                        @if ($c['integrity_verified'] !== null)
                            &mdash; integrity {{ $c['integrity_verified'] ? 'verified' : 'FAILED' }}
                        @endif
                        @if ($c['notes']) &mdash; {{ $c['notes'] }} @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    @can('permission', 'cases:manage')
        @if ($case->status !== 'CANCELLED')
            <div class="card-footer bg-transparent">
                <h2 class="h6">Cite evidence</h2>
                <form method="POST" action="{{ route('audit-cases.evidence.store', $case->id) }}" class="row g-2">
                    @csrf
                    <div class="col-md-2">
                        <label for="source_resource_type" class="form-label small mb-0">Source type</label>
                        <select id="source_resource_type" name="source_resource_type" class="form-select form-select-sm @error('source_resource_type') is-invalid @enderror" required
                            onchange="document.getElementById('checksum-field').hidden = this.value !== 'OTHER';">
                            <option value="INVOICE" @selected(old('source_resource_type') === 'INVOICE')>Invoice</option>
                            <option value="VAT_RETURN" @selected(old('source_resource_type') === 'VAT_RETURN')>VAT return</option>
                            <option value="DOCUMENT" @selected(old('source_resource_type') === 'DOCUMENT')>Document</option>
                            <option value="OTHER" @selected(old('source_resource_type') === 'OTHER')>Other (external)</option>
                        </select>
                        @error('source_resource_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="source_resource_id" class="form-label small mb-0">Source ID</label>
                        <input type="text" id="source_resource_id" name="source_resource_id" value="{{ old('source_resource_id') }}" class="form-control form-control-sm @error('source_resource_id') is-invalid @enderror" required>
                        @error('source_resource_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3" id="checksum-field" @if (old('source_resource_type') !== 'OTHER') hidden @endif>
                        <label for="checksum_sha256" class="form-label small mb-0">SHA-256 checksum</label>
                        <input type="text" id="checksum_sha256" name="checksum_sha256" value="{{ old('checksum_sha256') }}" class="form-control form-control-sm font-monospace @error('checksum_sha256') is-invalid @enderror">
                        @error('checksum_sha256')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="evidence-description" class="form-label small mb-0">Description</label>
                        <input type="text" id="evidence-description" name="description" value="{{ old('description') }}" class="form-control form-control-sm @error('description') is-invalid @enderror" minlength="10" maxlength="2000" required>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Add evidence</button>
                    </div>
                </form>
            </div>
        @endif
    @endcan
</div>

<div class="card mt-3">
    <div class="card-header"><div class="fw-semibold">Notes</div><div class="text-muted small">Append-only -- a correction is a new note, the original is never edited</div></div>
    <div class="card-body">
        @forelse ($notes as $note)
            <div class="mb-3 pb-3 border-bottom">
                <div class="d-flex justify-content-between">
                    <strong>{{ $actorName($note['author_id']) }}</strong>
                    <time class="text-muted small">{{ $dateTime($note['created_at']) }}</time>
                </div>
                <p class="mb-0">{{ $note['body'] }}</p>
            </div>
        @empty
            <p class="text-muted small mb-0">No notes yet.</p>
        @endforelse
    </div>
    @can('permission', 'cases:manage')
        <div class="card-footer bg-transparent">
            <form method="POST" action="{{ route('audit-cases.notes.store', $case->id) }}">
                @csrf
                <label for="body" class="visually-hidden">Note</label>
                <textarea id="body" name="body" class="form-control @error('body') is-invalid @enderror" minlength="5" maxlength="4000" rows="2" placeholder="Add a note..." required>{{ old('body') }}</textarea>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-primary btn-sm mt-2">Add note</button>
            </form>
        </div>
    @endcan
</div>
@endsection

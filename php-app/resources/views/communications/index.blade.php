@extends('layouts.app')

@section('title', 'Communications')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain</div>
    <h1 class="h3 mb-1">Communications</h1>
    <p class="text-muted mb-0">Formal correspondence threads tied to an audit case or reconciliation exception -- every notice, response and closure is recorded, never edited or deleted in place.</p>
</div>

@if ($canManage)
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Send a notice</h2>
            @if ($errors->any())
                <div class="alert alert-danger py-2" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('communications.notices.store') }}" class="row g-2">
                @csrf
                <x-idempotency-key />
                <div class="col-md-2">
                    <label for="related_resource_type" class="form-label small mb-0">Reference type</label>
                    <select id="related_resource_type" name="related_resource_type" class="form-select form-select-sm @error('related_resource_type') is-invalid @enderror" required>
                        <option value="AUDIT_CASE" @selected(old('related_resource_type') === 'AUDIT_CASE')>Audit case</option>
                        <option value="RECONCILIATION_EXCEPTION" @selected(old('related_resource_type') === 'RECONCILIATION_EXCEPTION')>Reconciliation exception</option>
                    </select>
                    @error('related_resource_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="related_resource_id" class="form-label small mb-0">Reference ID</label>
                    <input type="text" id="related_resource_id" name="related_resource_id" value="{{ old('related_resource_id') }}" class="form-control form-control-sm font-monospace @error('related_resource_id') is-invalid @enderror" required>
                    @error('related_resource_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="channel" class="form-label small mb-0">Channel</label>
                    <select id="channel" name="channel" class="form-select form-select-sm @error('channel') is-invalid @enderror" required>
                        @foreach (['PORTAL', 'EMAIL', 'SMS', 'LETTER'] as $c)
                            <option value="{{ $c }}" @selected(old('channel') === $c)>{{ ucfirst(strtolower($c)) }}</option>
                        @endforeach
                    </select>
                    @error('channel')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="classification" class="form-label small mb-0">Classification</label>
                    <select id="classification" name="classification" class="form-select form-select-sm @error('classification') is-invalid @enderror" required>
                        @foreach (['INTERNAL', 'CONFIDENTIAL', 'TAX_CONFIDENTIAL', 'RESTRICTED'] as $c)
                            <option value="{{ $c }}" @selected(old('classification') === $c)>{{ ucwords(strtolower(str_replace('_', ' ', $c))) }}</option>
                        @endforeach
                    </select>
                    @error('classification')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="subject" class="form-label small mb-0">Subject</label>
                    <input type="text" id="subject" name="subject" value="{{ old('subject') }}" class="form-control form-control-sm @error('subject') is-invalid @enderror" minlength="5" maxlength="200" required>
                    @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="content_summary" class="form-label small mb-0">Content summary</label>
                    <textarea id="content_summary" name="content_summary" class="form-control form-control-sm @error('content_summary') is-invalid @enderror" minlength="10" maxlength="4000" rows="2" required>{{ old('content_summary') }}</textarea>
                    @error('content_summary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 ms-auto">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Send notice</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('communications.index') }}" class="row g-2 align-items-center">
            <div class="col-md-6">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($status === '')>All statuses</option>
                    @foreach (['OPEN', 'CLOSED'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst(strtolower($s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 text-md-end small text-muted">{{ number_format($totalCount) }} thread{{ $totalCount === 1 ? '' : 's' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Correspondence threads, filterable by status</caption>
            <thead>
                <tr>
                    <th scope="col">Subject</th>
                    <th scope="col">Related to</th>
                    <th scope="col">Status</th>
                    <th scope="col">Messages</th>
                    <th scope="col">Latest message</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($threads as $thread)
                    <tr>
                        <td>
                            <a href="{{ route('communications.show', $thread['id']) }}"><strong>{{ $thread['subject'] }}</strong></a>
                            <div class="text-muted small">{{ ucwords(strtolower(str_replace('_', ' ', $thread['classification']))) }}</div>
                        </td>
                        <td>
                            {{ ucwords(strtolower(str_replace('_', ' ', $thread['related_resource_type']))) }}
                            <div class="text-muted small font-monospace">{{ $thread['related_resource_id'] }}</div>
                        </td>
                        <td><x-status-badge :value="$thread['status']" type="status" /></td>
                        <td>{{ number_format($thread['message_count']) }}</td>
                        <td>
                            <div class="text-truncate" style="max-width: 20rem;">{{ $thread['latest_message'] ?? '—' }}</div>
                            @if ($thread['latest_message_at'])
                                <div class="text-muted small">{{ \Illuminate\Support\Carbon::parse($thread['latest_message_at'])->format('d M Y, H:i') }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No correspondence threads match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

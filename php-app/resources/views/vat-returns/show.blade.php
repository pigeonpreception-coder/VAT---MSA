@extends('layouts.app')

@php
    $version = $detail['version'];
@endphp

@section('title', 'Return v'.$version['version_number'])

@php
    $money = fn (int $cents) => 'NAD '.number_format($cents / 100, 2);
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $pendingApproval = collect($detail['approvals'])->firstWhere('status', 'PENDING');
    $canDecideThis = $pendingApproval && $pendingApproval['requested_by'] !== $user->id;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">VAT return draft</div>
        <h1 class="h3 mb-1">Version {{ $version['version_number'] }}</h1>
        <p class="text-muted mb-0">Snapshot hash <span class="font-monospace small">{{ $version['ledger_snapshot_hash'] }}</span></p>
    </div>
    <a href="{{ route('vat-periods.show', $version['vat_period_id']) }}" class="btn btn-outline-secondary align-self-center">&larr; Back to period</a>
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
        <div class="fw-semibold">Return position</div>
        <x-status-badge :value="$version['status']" type="status" />
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">The four computed boxes making up this return's net position</caption>
            <thead>
                <tr><th scope="col">Box</th><th scope="col" class="text-end">Amount</th><th scope="col" class="text-end">Sources</th></tr>
            </thead>
            <tbody>
                @foreach ($detail['boxes'] as $box)
                    <tr class="{{ $box['box_code'] === 'BOX_NET' ? 'fw-semibold table-active' : '' }}">
                        <td>{{ $box['label'] }}</td>
                        <td class="text-end">{{ $money($box['amount_cents']) }}</td>
                        <td class="text-end">{{ $box['source_count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent">
        <div class="row row-cols-2 row-cols-md-4 g-2 text-center">
            <div class="col"><div class="text-muted small">Generated</div><div class="fw-semibold">{{ $dateTime($version['generated_at']) }}</div></div>
            <div class="col"><div class="text-muted small">Approved</div><div class="fw-semibold">{{ $dateTime($version['approved_at']) }}</div></div>
            <div class="col"><div class="text-muted small">Superseded</div><div class="fw-semibold">{{ $dateTime($version['superseded_at']) }}</div></div>
            <div class="col"><div class="text-muted small">Net payable</div><div class="fw-semibold">{{ $money($version['net_payable_cents']) }}</div></div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    @can('permission', 'returns:generate')
        @if ($version['status'] === 'DRAFT')
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Request approval</h2>
                        <p class="text-muted small">Sends this draft to a maker-checker reviewer. Once approved, the VAT period locks.</p>
                        <form method="POST" action="{{ route('vat-returns.approval-request.store', $version['id']) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Request approval</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    @can('permission', 'returns:submit')
        @if ($version['status'] === 'APPROVED')
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Submit to ITAS</h2>
                        <p class="text-muted small">Submits the approved return to the tax authority's submission provider.</p>
                        <form method="POST" action="{{ route('vat-returns.submission.store', $version['id']) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Submit to ITAS</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    @can('permission', 'returns:approve')
        @if ($pendingApproval)
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Decide approval</h2>
                        @if ($canDecideThis)
                            <form method="POST" action="{{ route('approval-tasks.decision.store', $pendingApproval['id']) }}">
                                @csrf
                                <div class="mb-2">
                                    <label for="comment" class="form-label">Comment</label>
                                    <textarea id="comment" name="comment" class="form-control @error('comment') is-invalid @enderror" minlength="5" maxlength="1000" rows="2" required>{{ old('comment') }}</textarea>
                                    @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <button type="submit" name="decision" value="APPROVE" class="btn btn-success btn-sm">Approve</button>
                                <button type="submit" name="decision" value="REJECT" class="btn btn-outline-danger btn-sm">Reject</button>
                            </form>
                        @else
                            <p class="text-muted small mb-0">Maker-checker separation prevents you from deciding a return you requested approval for.</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endcan
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Approval history</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Approval decisions requested against this return</caption>
            <thead>
                <tr><th scope="col">Action</th><th scope="col">Risk</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col">Decided</th><th scope="col">Comment</th></tr>
            </thead>
            <tbody>
                @forelse ($detail['approvals'] as $task)
                    <tr>
                        <td>{{ $titleCase($task['requested_action']) }}</td>
                        <td><x-status-badge :value="$task['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$task['status']" type="status" /></td>
                        <td>{{ $dateTime($task['requested_at']) }}</td>
                        <td>{{ $dateTime($task['decided_at']) }}</td>
                        <td>{{ $task['decision_comment'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No approval requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Adjustments applied to this period</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">VAT adjustments recorded against this return's period</caption>
            <thead>
                <tr><th scope="col">Type</th><th scope="col">Direction</th><th scope="col" class="text-end">Amount</th><th scope="col">Reason</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
                @forelse ($detail['adjustments'] as $adjustment)
                    <tr>
                        <td>{{ $titleCase($adjustment['adjustment_type']) }}</td>
                        <td>{{ $titleCase($adjustment['direction']) }}</td>
                        <td class="text-end">{{ $money($adjustment['amount_cents']) }}</td>
                        <td>{{ $adjustment['reason_code'] }}</td>
                        <td><x-status-badge :value="$adjustment['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No adjustments recorded for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Submission history</div>
        <div class="text-muted small">Attempts to file this return with the tax authority's submission provider</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Submission attempts for this return</caption>
            <thead>
                <tr><th scope="col">Provider</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col" class="text-end">Attempts</th><th scope="col">Submitted</th><th scope="col">Error</th></tr>
            </thead>
            <tbody>
                @forelse ($detail['submissions'] as $submission)
                    <tr>
                        <td>{{ $submission['provider'] }}</td>
                        <td class="font-monospace small">{{ $submission['provider_reference'] ?? '—' }}</td>
                        <td><x-status-badge :value="$submission['status']" type="status" /></td>
                        <td class="text-end">{{ $submission['attempt_count'] }}</td>
                        <td>{{ $dateTime($submission['submitted_at']) }}</td>
                        <td class="text-danger small">{{ $submission['last_error'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Not yet submitted.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', $period['period_code'])

@php
    $money = fn (int $cents) => 'NAD '.number_format($cents / 100, 2);
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $blockingStatuses = ['PENDING_APPROVAL', 'APPROVED', 'AWAITING_PROVIDER', 'FILED'];
    $returnIsBlocked = in_array($period['return_status'] ?? null, $blockingStatuses, true);
    $canGenerateNow = $canGenerateReturn && $period['status'] === 'OPEN' && ! $returnIsBlocked;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">VAT period</div>
        <h1 class="h3 mb-1">{{ $period['period_code'] }}</h1>
        <p class="text-muted mb-0">{{ $period['legal_name'] }} &middot; {{ $period['vat_number'] }}</p>
    </div>
    <a href="{{ route('vat-periods.index') }}" class="btn btn-outline-secondary align-self-center">&larr; Back to VAT periods</a>
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

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">Period record</div>
                <x-status-badge :value="$period['status']" type="status" />
            </div>
            <dl class="card-body row row-cols-1 row-cols-sm-2 g-3 mb-0">
                <div class="col"><dt class="text-muted small">Period start</dt><dd class="mb-0 fw-semibold">{{ $date($period['period_start']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Period end</dt><dd class="mb-0 fw-semibold">{{ $date($period['period_end']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Due date</dt><dd class="mb-0 fw-semibold">{{ $date($period['due_date']) }}</dd></div>
                <div class="col"><dt class="text-muted small">Lock version</dt><dd class="mb-0 fw-semibold">{{ $period['lock_version'] }}</dd></div>
                <div class="col"><dt class="text-muted small">Matched invoices</dt><dd class="mb-0 fw-semibold text-success">{{ $period['matched_count'] }}</dd></div>
                <div class="col"><dt class="text-muted small">Unmatched invoices</dt><dd class="mb-0 fw-semibold {{ $period['unmatched_count'] > 0 ? 'text-warning' : '' }}">{{ $period['unmatched_count'] }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">Latest return</div>
            @if ($period['latest_return_id'])
                <dl class="card-body row row-cols-1 row-cols-sm-2 g-3 mb-0">
                    <div class="col"><dt class="text-muted small">Version</dt><dd class="mb-0 fw-semibold">v{{ $period['latest_version'] }}</dd></div>
                    <div class="col"><dt class="text-muted small">Status</dt><dd class="mb-0"><x-status-badge :value="$period['return_status']" type="status" /></dd></div>
                    <div class="col"><dt class="text-muted small">Net payable</dt><dd class="mb-0 fw-semibold">{{ $money($period['net_payable_cents']) }}</dd></div>
                    <div class="col"></div>
                </dl>
                <div class="card-footer bg-transparent">
                    <a href="{{ route('vat-returns.show', $period['latest_return_id']) }}" class="btn btn-sm btn-primary">View return detail</a>
                </div>
            @else
                <div class="card-body">
                    <p class="text-muted mb-3">No return has been generated for this period yet.</p>
                    @if ($canGenerateNow)
                        <form method="POST" action="{{ route('vat-periods.return.store', $period['id']) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Generate return</button>
                        </form>
                    @elseif ($period['status'] !== 'OPEN')
                        <p class="text-muted small mb-0">The period must be open to generate a return.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

@if ($period['latest_return_id'] && $canGenerateNow)
    <div class="card mt-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <p class="text-muted mb-0">A new draft return can be generated on top of the current one (e.g. after approved adjustments).</p>
            <form method="POST" action="{{ route('vat-periods.return.store', $period['id']) }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">Generate new draft return</button>
            </form>
        </div>
    </div>
@endif

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">VAT adjustments</div>
        <div class="text-muted small">Manual corrections to output/input VAT or the net position, each maker-checker approved</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">VAT adjustments recorded against this period</caption>
            <thead>
                <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Direction</th>
                    <th scope="col" class="text-end">Amount</th>
                    <th scope="col">Reason</th>
                    <th scope="col">Status</th>
                    <th scope="col">Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periodAdjustments as $adjustment)
                    <tr>
                        <td>{{ $titleCase($adjustment['adjustment_type']) }}</td>
                        <td>{{ $titleCase($adjustment['direction']) }}</td>
                        <td class="text-end">{{ $money($adjustment['amount_cents']) }}</td>
                        <td>
                            {{ $adjustment['reason_code'] }}
                            <div class="text-muted small">{{ $adjustment['explanation'] }}</div>
                        </td>
                        <td><x-status-badge :value="$adjustment['status']" type="status" /></td>
                        <td>{{ $date($adjustment['created_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No adjustments recorded for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canApprove && $pendingAdjustmentApprovals->isNotEmpty())
        <div class="card-footer bg-transparent">
            <h2 class="h6">Pending adjustment approvals</h2>
            @foreach ($periodAdjustments as $adjustment)
                @continue (! isset($pendingAdjustmentApprovals[$adjustment['id']]))
                @php $task = $pendingAdjustmentApprovals[$adjustment['id']]; @endphp
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <strong>{{ $titleCase($adjustment['adjustment_type']) }}</strong> &middot; {{ $titleCase($adjustment['direction']) }} &middot; {{ $money($adjustment['amount_cents']) }}
                            <div class="text-muted small">{{ $adjustment['reason_code'] }} &mdash; {{ $adjustment['explanation'] }}</div>
                        </div>
                    </div>
                    @if ($task->requested_by === $currentUserId)
                        <p class="text-muted small mb-0">Maker-checker separation prevents you from deciding an adjustment you requested approval for.</p>
                    @else
                        <form method="POST" action="{{ route('approval-tasks.decision.store', $task->id) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-8">
                                <label for="comment-{{ $task->id }}" class="form-label">Comment</label>
                                <input type="text" id="comment-{{ $task->id }}" name="comment" value="{{ old('comment') }}" class="form-control @error('comment') is-invalid @enderror" minlength="5" maxlength="1000" required>
                                @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="decision" value="APPROVE" class="btn btn-success btn-sm">Approve</button>
                                <button type="submit" name="decision" value="REJECT" class="btn btn-outline-danger btn-sm">Reject</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canManageAdjustments && $period['status'] === 'OPEN')
        <div class="card-footer bg-transparent">
            <h2 class="h6">Submit a new adjustment</h2>
            <form method="POST" action="{{ route('vat-periods.adjustments.store', $period['id']) }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label for="adjustment_type" class="form-label">Type</label>
                    <select id="adjustment_type" name="adjustment_type" class="form-select @error('adjustment_type') is-invalid @enderror" required>
                        <option value="OUTPUT_TAX" @selected(old('adjustment_type') === 'OUTPUT_TAX')>Output tax</option>
                        <option value="INPUT_TAX" @selected(old('adjustment_type') === 'INPUT_TAX')>Input tax</option>
                        <option value="NET_PAYABLE" @selected(old('adjustment_type') === 'NET_PAYABLE')>Net payable</option>
                    </select>
                    @error('adjustment_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="direction" class="form-label">Direction</label>
                    <select id="direction" name="direction" class="form-select @error('direction') is-invalid @enderror" required>
                        <option value="INCREASE" @selected(old('direction') === 'INCREASE')>Increase</option>
                        <option value="DECREASE" @selected(old('direction') === 'DECREASE')>Decrease</option>
                    </select>
                    @error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="amount" class="form-label">Amount (NAD)</label>
                    <input type="number" step="0.01" min="0.01" id="amount" name="amount" value="{{ old('amount') }}" class="form-control @error('amount_cents') is-invalid @enderror" required>
                    @error('amount_cents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="reason_code" class="form-label">Reason code</label>
                    <input type="text" id="reason_code" name="reason_code" value="{{ old('reason_code') }}" class="form-control @error('reason_code') is-invalid @enderror" placeholder="e.g. LATE_INVOICE" required>
                    @error('reason_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="explanation" class="form-label">Explanation</label>
                    <input type="text" id="explanation" name="explanation" value="{{ old('explanation') }}" class="form-control @error('explanation') is-invalid @enderror" minlength="10" maxlength="2000" required>
                    @error('explanation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Submit adjustment for approval</button>
                </div>
            </form>
        </div>
    @endif
</div>
@endsection

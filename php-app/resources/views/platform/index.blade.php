@extends('layouts.app')

@section('title', 'Platform config')

@php
    $sidePreviewLimit = 5;
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Platform</div>
    <h1 class="h3 mb-1">Feature flags, platform config &amp; access policies</h1>
    <p class="text-muted mb-0">Only the value of an existing definition is runtime-changeable, and only through a maker-checker gate: a proposed change is staged until a second, independent reviewer decides it.</p>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Feature flags</span><span>F</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['feature_flags']) }}</div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Config values</span><span>C</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['platform_config']) }}</div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Access policies</span><span>A</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['access_policies']) }}</div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Pending changes</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['pending_changes']) }}</div>
                <div class="small {{ $metrics['pending_changes'] > 0 ? 'text-warning' : 'text-muted' }}">Awaiting independent decision</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <div class="fw-semibold">Feature flags</div>
                <div class="text-muted small">Proposing a change stages it as PENDING; nothing changes until an independent reviewer approves it</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Active feature flags with their current enabled state and a propose-change action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Key</th>
                            <th scope="col">Rollout</th>
                            <th scope="col">Enabled</th>
                            @if ($canManage)
                                <th scope="col">Propose change</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($config['feature_flags'] as $flag)
                            <tr>
                                <td>
                                    <span class="font-monospace">{{ $flag['key'] }}</span>
                                    <div class="text-muted small">{{ $flag['name'] }}</div>
                                    <div class="text-muted small">{{ $flag['description'] }}</div>
                                </td>
                                <td>{{ str_replace('_', ' ', $flag['rollout_scope']) }}</td>
                                <td><x-status-badge :value="$flag['enabled'] ? 'ACTIVE' : 'CANCELLED'" type="status" /></td>
                                @if ($canManage)
                                    <td>
                                        <form method="POST" action="{{ route('platform.change-requests.store') }}">
                                            @csrf
                                            <x-idempotency-key/>
                                            <input type="hidden" name="target_type" value="FEATURE_FLAG">
                                            <input type="hidden" name="target_id" value="{{ $flag['id'] }}">
                                            <input type="hidden" name="enabled" value="{{ $flag['enabled'] ? '0' : '1' }}">
                                            <input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500">
                                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap w-100">Propose {{ $flag['enabled'] ? 'disable' : 'enable' }}</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 4 : 3 }}" class="text-center text-muted py-4">No active feature flags.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Platform config values</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Active platform config values with a propose-change action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Key &amp; category</th>
                            <th scope="col">Value</th>
                            @if ($canManage)
                                <th scope="col">Propose change</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($config['platform_config'] as $entry)
                            <tr>
                                <td>
                                    <span class="font-monospace">{{ $entry['key'] }}</span>
                                    <div class="text-muted small">{{ $entry['category'] }} &middot; {{ $entry['description'] }}</div>
                                </td>
                                <td><span class="font-monospace">{{ $entry['value'] }}</span></td>
                                @if ($canManage)
                                    <td>
                                        <form method="POST" action="{{ route('platform.change-requests.store') }}">
                                            @csrf
                                            <x-idempotency-key/>
                                            <input type="hidden" name="target_type" value="PLATFORM_CONFIG">
                                            <input type="hidden" name="target_id" value="{{ $entry['id'] }}">
                                            <input type="text" name="value" class="form-control form-control-sm mb-1" placeholder="New value" required>
                                            <input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500">
                                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap w-100">Propose</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 3 : 2 }}" class="text-center text-muted py-4">No active platform config values.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Access policies</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Active access policies with their parameters and a propose-change action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Code &amp; type</th>
                            <th scope="col">Parameters</th>
                            @if ($canManage)
                                <th scope="col">Propose change</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($config['access_policies'] as $policy)
                            <tr>
                                <td>
                                    <span class="font-monospace">{{ $policy['code'] }}</span>
                                    <div class="text-muted small">{{ $policy['name'] }} &middot; {{ str_replace('_', ' ', $policy['policy_type']) }}</div>
                                </td>
                                <td><span class="font-monospace small">{{ json_encode($policy['parameters']) }}</span></td>
                                @if ($canManage)
                                    <td>
                                        <form method="POST" action="{{ route('platform.change-requests.store') }}">
                                            @csrf
                                            <x-idempotency-key/>
                                            <input type="hidden" name="target_type" value="ACCESS_POLICY">
                                            <input type="hidden" name="target_id" value="{{ $policy['id'] }}">
                                            <input type="text" name="parameters" class="form-control form-control-sm font-monospace mb-1" placeholder='{"key":"value"}' required value="{{ json_encode($policy['parameters']) }}">
                                            <input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500">
                                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap w-100">Propose</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 3 : 2 }}" class="text-center text-muted py-4">No active access policies.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Change requests</span>
                <span class="badge text-bg-light border">{{ count($changeRequests) }}</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">A reviewer may never decide a change request they submitted themselves.</p>
                @forelse ($changeRequests as $index => $change)
                    <div class="mb-3 pb-3 border-bottom change-request-item" @if ($index >= $sidePreviewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <span>{{ str_replace('_', ' ', $change['target_type']) }}</span>
                            <x-status-badge :value="$change['status']" type="status" />
                        </div>
                        <div class="text-muted small font-monospace mt-1">{{ $change['target_id'] }}</div>
                        <div class="small mt-1">{{ $change['reason'] }}</div>
                        <div class="text-muted small mt-1">{{ \Illuminate\Support\Carbon::parse($change['requested_at'])->format('d M Y, H:i') }}</div>
                        @if ($canManage && $change['status'] === 'PENDING')
                            <form method="POST" action="{{ route('platform.change-requests.decide', $change['id']) }}" class="mt-2">
                                @csrf
                                <x-idempotency-key/>
                                <input type="text" name="notes" class="form-control form-control-sm mb-1" placeholder="Decision notes">
                                <div class="d-flex gap-1">
                                    <button type="submit" name="decision" value="APPROVE" class="btn btn-sm btn-outline-success w-100">Approve</button>
                                    <button type="submit" name="decision" value="REJECT" class="btn btn-sm btn-outline-danger w-100">Reject</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-muted small mb-0">No change requests yet.</p>
                @endforelse
                @if (count($changeRequests) > $sidePreviewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="change-request-item" data-show-more-count="{{ count($changeRequests) - $sidePreviewLimit }}">
                        Show {{ count($changeRequests) - $sidePreviewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($canManage)
    <div class="card mt-3">
        <div class="card-header">
            <div class="fw-semibold">Provision platform staff</div>
            <div class="text-muted small">A national/technical account with no taxpayer organisation -- unconditionally step-up gated</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform.staff.store') }}">
                @csrf
                <x-idempotency-key/>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="external_user_id" class="form-label">External user ID</label>
                        <input type="text" class="form-control" id="external_user_id" name="external_user_id" required minlength="2" maxlength="100" value="{{ old('external_user_id') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required value="{{ old('email') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="display_name" class="form-label">Display name</label>
                        <input type="text" class="form-control" id="display_name" name="display_name" required minlength="2" maxlength="120" value="{{ old('display_name') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="" disabled selected>Select role</option>
                            @foreach ($staffRoles as $role)
                                <option value="{{ $role }}" @selected(old('role') === $role)>{{ str_replace('_', ' ', $role) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Provision staff account</button>
            </form>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-show-more-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.dataset.expanded === '1';
                var items = document.querySelectorAll('.' + button.dataset.showMoreTarget);
                items.forEach(function (item, index) {
                    if (index >= 5) { item.hidden = expanded; }
                });
                button.dataset.expanded = expanded ? '0' : '1';
                button.textContent = expanded
                    ? 'Show ' + button.dataset.showMoreCount + ' more'
                    : 'Show fewer';
            });
        });
    })();
</script>
@endpush

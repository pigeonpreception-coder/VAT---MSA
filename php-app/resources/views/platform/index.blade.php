@extends('layouts.app')

@section('title', 'Platform config')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Platform</div>
    <h1 class="h3 mb-1">Feature flags, platform config &amp; access policies</h1>
    <p class="text-muted mb-0">Only the value of an existing definition is runtime-changeable, and only through a maker-checker gate: a proposed change is staged until a second, independent reviewer decides it.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

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
                    <th scope="col">Description</th>
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
                        <td><span class="font-monospace">{{ $flag['key'] }}</span><div class="text-muted small">{{ $flag['name'] }}</div></td>
                        <td>{{ $flag['description'] }}</td>
                        <td>{{ str_replace('_', ' ', $flag['rollout_scope']) }}</td>
                        <td><x-status-badge :value="$flag['enabled'] ? 'ACTIVE' : 'CANCELLED'" type="status" /></td>
                        @if ($canManage)
                            <td>
                                <form method="POST" action="{{ route('platform.change-requests.store') }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="hidden" name="target_type" value="FEATURE_FLAG">
                                    <input type="hidden" name="target_id" value="{{ $flag['id'] }}">
                                    <input type="hidden" name="enabled" value="{{ $flag['enabled'] ? '0' : '1' }}">
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500" style="width: 12rem;">
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Propose {{ $flag['enabled'] ? 'disable' : 'enable' }}</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No active feature flags.</td></tr>
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
                    <th scope="col">Key</th>
                    <th scope="col">Category</th>
                    <th scope="col">Value</th>
                    @if ($canManage)
                        <th scope="col">Propose change</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($config['platform_config'] as $entry)
                    <tr>
                        <td><span class="font-monospace">{{ $entry['key'] }}</span><div class="text-muted small">{{ $entry['description'] }}</div></td>
                        <td>{{ $entry['category'] }}</td>
                        <td><span class="font-monospace">{{ $entry['value'] }}</span></td>
                        @if ($canManage)
                            <td>
                                <form method="POST" action="{{ route('platform.change-requests.store') }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="hidden" name="target_type" value="PLATFORM_CONFIG">
                                    <input type="hidden" name="target_id" value="{{ $entry['id'] }}">
                                    <input type="text" name="value" class="form-control form-control-sm" placeholder="New value" required style="width: 8rem;">
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500" style="width: 12rem;">
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Propose</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 4 : 3 }}" class="text-center text-muted py-4">No active platform config values.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">Access policies</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Active access policies with their parameters and a propose-change action</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Type</th>
                    <th scope="col">Parameters</th>
                    @if ($canManage)
                        <th scope="col">Propose change</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($config['access_policies'] as $policy)
                    <tr>
                        <td><span class="font-monospace">{{ $policy['code'] }}</span><div class="text-muted small">{{ $policy['name'] }}</div></td>
                        <td>{{ str_replace('_', ' ', $policy['policy_type']) }}</td>
                        <td><span class="font-monospace small">{{ json_encode($policy['parameters']) }}</span></td>
                        @if ($canManage)
                            <td>
                                <form method="POST" action="{{ route('platform.change-requests.store') }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="hidden" name="target_type" value="ACCESS_POLICY">
                                    <input type="hidden" name="target_id" value="{{ $policy['id'] }}">
                                    <input type="text" name="parameters" class="form-control form-control-sm font-monospace" placeholder='{"key":"value"}' required style="width: 10rem;" value="{{ json_encode($policy['parameters']) }}">
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (min 5 chars)" required minlength="5" maxlength="500" style="width: 12rem;">
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Propose</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 4 : 3 }}" class="text-center text-muted py-4">No active access policies.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Change requests</div>
        <div class="text-muted small">A reviewer may never decide a change request they submitted themselves</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Platform change requests with their status and a decide action for pending ones</caption>
            <thead>
                <tr>
                    <th scope="col">Target</th>
                    <th scope="col">Reason</th>
                    <th scope="col">Status</th>
                    <th scope="col">Requested</th>
                    @if ($canManage)
                        <th scope="col">Decide</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($changeRequests as $change)
                    <tr>
                        <td>{{ str_replace('_', ' ', $change['target_type']) }}<div class="text-muted small font-monospace">{{ $change['target_id'] }}</div></td>
                        <td>{{ $change['reason'] }}</td>
                        <td><x-status-badge :value="$change['status']" type="status" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($change['requested_at'])->format('d M Y, H:i') }}</td>
                        @if ($canManage)
                            <td>
                                @if ($change['status'] === 'PENDING')
                                    <form method="POST" action="{{ route('platform.change-requests.decide', $change['id']) }}" class="d-flex gap-2">
                                        @csrf
                                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Decision notes" style="width: 10rem;">
                                        <button type="submit" name="decision" value="APPROVE" class="btn btn-sm btn-outline-success text-nowrap">Approve</button>
                                        <button type="submit" name="decision" value="REJECT" class="btn btn-sm btn-outline-danger text-nowrap">Reject</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No change requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($canManage)
    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold">Provision platform staff</div>
            <div class="text-muted small">A national/technical account with no taxpayer organisation -- unconditionally step-up gated</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('platform.staff.store') }}">
                @csrf
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

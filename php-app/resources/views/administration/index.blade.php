@extends('layouts.app')

@section('title', 'Organisation administration')

@php
    $activeEmployees = collect($snapshot['employees'])->where('status', 'ACTIVE')->count();
    $seat = collect($snapshot['entitlements'])->firstWhere('feature_key', 'USER_SEATS');
    $openAccessReviews = collect($snapshot['accessReviews'])->where('status', 'OPEN')->count();
    // capacityExceptions has no backing table in this port at all (unlike
    // every other snapshot field here) -- always empty, not a display bug.
    // See docs/MIGRATION_MATRIX.md's Administration section.
    $openCapacityExceptions = collect($snapshot['capacityExceptions'] ?? [])->where('status', 'OPEN');
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Organisation control plane</div>
    <h1 class="h3 mb-1">Administration command centre</h1>
    <p class="text-muted mb-0">Licensed organisation structure, employment identity, least-privilege roles, immutable workflows and quarterly access governance. Employment position never grants access by itself.</p>
</div>

<div class="alert alert-info" role="status">
    <strong>Local/staging safety boundary:</strong> synthetic data only. Real payments, outbound email, live ITAS connectivity and unapproved statutory rules remain disabled.
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Licence</div>
            <div class="fs-4 fw-semibold">{{ $snapshot['license']['plan_name'] }}</div>
            <div class="small text-success">Price-free configurable placeholder &middot; {{ $snapshot['license']['state'] }}</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">User seats</div>
            <div class="fs-2 fw-semibold">{{ number_format($seat['used_value'] ?? $activeEmployees) }} / {{ ($seat['capacity_mode'] ?? null) === 'UNLIMITED' ? 'Unlimited' : ($seat['limit_value'] ?? '—') }}</div>
            <div class="small text-muted">{{ $seat['capacity_mode'] ?? 'NOT CONFIGURED' }} &middot; invitations reserve capacity</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Pending approvals</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['tasks'])) }}</div>
            <div class="small text-warning">Self-approval and emergency override disabled</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Access reviews</div>
            <div class="fs-2 fw-semibold">{{ number_format($openAccessReviews) }}</div>
            <div class="small text-muted">Quarterly certification cadence</div>
        </div></div>
    </div>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>This action needs attention.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@foreach ($openCapacityExceptions as $exception)
    <div class="alert alert-danger" role="alert">
        <strong>Licence capacity exception:</strong> {{ $exception['active_users'] }} active or invited users exceed the activated capacity of {{ $exception['licensed_capacity'] }}. No users were deleted. Deactivate memberships non-destructively or complete an approved upgrade before inviting another employee.
    </div>
@endforeach

@if ($canManageEmployees && $canManageRoles)
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold">Invite employee</div>
                    <div class="text-muted small">Seat limit checked atomically; no email is sent</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('administration.employees.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="employee_number" class="form-label">Employee number</label>
                            <input type="text" class="form-control" id="employee_number" name="employee_number" required maxlength="40" placeholder="EMP-004" value="{{ old('employee_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="120" placeholder="Synthetic Test User" value="{{ old('full_name') }}">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required placeholder="synthetic.user@example.test" value="{{ old('email') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Record invitation</button>
                        <div class="form-text">You'll be asked to confirm your password before this privileged change is applied.</div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold">Create organisation role</div>
                    <div class="text-muted small">Protected platform and statutory permissions are excluded</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('administration.roles.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label">Role name</label>
                            <input type="text" class="form-control" id="name" name="name" required maxlength="80" placeholder="Branch VAT Reviewer" value="{{ old('name') }}">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" required maxlength="240" placeholder="Reviews branch VAT evidence" value="{{ old('description') }}">
                        </div>
                        <div class="mb-3">
                            <label for="permissions" class="form-label">Permission codes</label>
                            <input type="text" class="form-control" id="permissions" name="permissions" required placeholder="invoices:read, returns:read" value="{{ old('permissions') }}">
                            <div class="form-text">Comma-separated entries from the approved access catalogue.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Create role</button>
                        <div class="form-text">You'll be asked to confirm your password before this privileged change is applied.</div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Employees and employment structure</div>
                <div class="text-muted small">{{ $snapshot['structures']['departments'] ?? 0 }} departments &middot; {{ $snapshot['structures']['branches'] ?? 0 }} branches &middot; {{ $snapshot['structures']['job_titles'] ?? 0 }} job titles</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Employees, their employment details, last activity and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Employment</th>
                            <th scope="col">Last activity</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshot['employees'] as $employee)
                            <tr>
                                <td>
                                    <strong>{{ $employee['full_name'] }}</strong>
                                    <div class="text-muted small font-monospace">{{ $employee['employee_number'] }} &middot; {{ $employee['email'] }}</div>
                                </td>
                                <td>
                                    {{ $employee['job_title'] ?? 'Unassigned' }}
                                    <div class="text-muted small">{{ $employee['department'] ?? 'No department' }} &middot; {{ $employee['branch'] ?? 'No branch' }}</div>
                                </td>
                                <td>{{ $employee['last_activity_at'] ? \Illuminate\Support\Carbon::parse($employee['last_activity_at'])->format('d M Y, H:i') : 'Not yet active' }}</td>
                                <td><x-status-badge :value="$employee['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No employees on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Organisation roles</div>
                <div class="text-muted small">Job titles and access roles remain separate</div>
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($snapshot['roles'] as $role)
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong>{{ $role['name'] }}</strong>
                            <p class="mb-0 small">{{ $role['description'] }}</p>
                            <span class="text-muted small font-monospace">{{ $role['permissions'] ?: 'No permissions' }}</span>
                        </div>
                        <x-status-badge :value="$role['status']" type="status" />
                    </li>
                @empty
                    <li class="list-group-item text-center text-muted py-4">No organisation roles on record.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Versioned workflows</div>
                <div class="text-muted small">Published versions are immutable and use typed conditions only</div>
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($snapshot['workflows'] as $workflow)
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong>{{ $workflow['name'] }}</strong>
                            <p class="mb-0 small">{{ $titleCase($workflow['domain_action']) }} &middot; version {{ $workflow['version_number'] ?? 'draft' }}</p>
                        </div>
                        <x-status-badge :value="$workflow['version_status'] ?? $workflow['status']" type="status" />
                    </li>
                @empty
                    <li class="list-group-item text-center text-muted py-4">No workflows on record.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Access governance</div>
                <div class="text-muted small">Quarterly reviews and dual-control requests</div>
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($snapshot['accessReviews'] as $review)
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong>{{ $review['name'] }}</strong>
                            <p class="mb-0 small">{{ $titleCase($review['review_type']) }} &middot; due {{ \Illuminate\Support\Carbon::parse($review['due_at'])->format('d M Y, H:i') }}</p>
                        </div>
                        <x-status-badge :value="$review['status']" type="status" />
                    </li>
                @empty
                    <li class="list-group-item text-center text-muted py-4">No access reviews on record.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div class="fw-semibold">Licence entitlements and usage</div>
            <div class="text-muted small">No prices configured &middot; expiry is non-destructive</div>
        </div>
        <x-status-badge :value="$snapshot['license']['state']" type="status" />
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Licence entitlements, their usage, capacity mode, limit and enabled state</caption>
            <thead>
                <tr>
                    <th scope="col">Feature</th>
                    <th scope="col">Meter</th>
                    <th scope="col">Usage</th>
                    <th scope="col">Capacity mode</th>
                    <th scope="col">Limit</th>
                    <th scope="col">Entitled</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($snapshot['entitlements'] as $entitlement)
                    <tr>
                        <td>
                            <strong>{{ $entitlement['name'] }}</strong>
                            <div class="text-muted small">{{ $entitlement['description'] }}</div>
                        </td>
                        <td class="font-monospace">{{ $entitlement['metric_key'] ?? 'Unmetered' }}</td>
                        <td>{{ number_format($entitlement['used_value'] + $entitlement['reserved_value']) }}</td>
                        <td><x-status-badge :value="$entitlement['capacity_mode']" type="status" /></td>
                        <td>{{ $entitlement['capacity_mode'] === 'UNLIMITED' ? 'Unlimited' : ($entitlement['limit_value'] ?? 'Not applicable') }}</td>
                        <td><x-status-badge :value="$entitlement['enabled'] ? 'ACTIVE' : 'DISABLED'" type="status" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-0" role="status">On suspension, expiry or cancellation, records are retained. Authorised read, export, compliance and correction operations continue; licence-expanding administration is denied.</div>
    </div>
</div>
@endsection

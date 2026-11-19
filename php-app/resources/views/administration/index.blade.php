@extends('layouts.app')

@section('title', 'Organisation administration')

@php
    $user = auth()->user();
    $employees = collect($snapshot['employees']);
    $roles = collect($snapshot['roles']);
    $structures = $snapshot['structures'];

    // capacity_mode (NOT_APPLICABLE / UNLIMITED / FINITE) is computed by
    // App\Support\Licensing\LicenseResolver::getEntitlements on every row.
    $seat = collect($snapshot['entitlements'])->firstWhere('feature_key', 'USER_SEATS');
    $seatsReserved = (int) ($seat['reserved_value'] ?? 0);
    $seatsInUse = (int) ($seat['used_value'] ?? $employees->where('status', 'ACTIVE')->count()) + $seatsReserved;
    $seatsFinite = ($seat['capacity_mode'] ?? null) === 'FINITE' && isset($seat['limit_value']);
    $seatValue = number_format($seatsInUse).($seatsFinite ? ' / '.number_format($seat['limit_value']) : '');
    $seatHint = match (true) {
        $seatsReserved > 0 => 'Includes '.$seatsReserved.' pending '.\Illuminate\Support\Str::plural('invitation', $seatsReserved),
        ($seat['capacity_mode'] ?? null) === 'UNLIMITED' => 'No seat limit',
        $seatsFinite => 'Seats in use',
        default => 'Seat limit not configured',
    };

    $openAccessReviews = collect($snapshot['accessReviews'])->where('status', 'OPEN')->count();
    $reviewOverdue = $currentReview && ! $adminWritesOpen;
    $canStartReview = $user->hasAppPermission('access-governance:manage');
    $reviewHint = match (true) {
        $adminWritesOpen => $reviewWindow.' review in place',
        $reviewOverdue => $reviewWindow.' review is overdue',
        default => $reviewWindow.' review not started',
    };

    // capacityExceptions has no backing table in this port -- always empty
    // today. See docs/MIGRATION_MATRIX.md's Administration section.
    $openCapacityExceptions = collect($snapshot['capacityExceptions'] ?? [])->where('status', 'OPEN');

    $activeTab = session('adminTab') ?? (($errors->has('role') || request('tab') === 'roles') ? 'roles' : 'people');
    $selectedPermissions = old('permissions', []);
    $selectedPermissions = is_array($selectedPermissions) ? $selectedPermissions : array_map('trim', explode(',', (string) $selectedPermissions));
@endphp

@section('content')
<x-page-header title="Organisation administration"
    :subtitle="$snapshot['organisation']['legal_name'].' — manage your team, roles and licence seats.'" />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Licence" icon="award" :value="$snapshot['license']['plan_name']"
            :href="$user->hasAppPermission('licensing:read') ? route('licensing.index') : null">
            <div class="stat-hint"><x-status-badge :value="$snapshot['license']['state']" type="license" /></div>
        </x-stat-tile>
    </div>
    <div class="col">
        <x-stat-tile label="User seats" icon="people" :value="$seatValue" :hint="$seatHint" />
    </div>
    <div class="col">
        <x-stat-tile label="Pending approvals" icon="hourglass-split" :value="number_format(count($snapshot['tasks']))"
            hint="Workflow tasks waiting for a decision"
            :href="$user->hasAppPermission('workflows:read') ? route('workflows.index') : null" />
    </div>
    <div class="col">
        <x-stat-tile label="Access reviews" icon="clipboard-check" :value="number_format($openAccessReviews).' open'"
            :hint="$reviewHint"
            :tone="$adminWritesOpen ? null : 'warning'"
            :href="$user->hasAppPermission('access-governance:read') ? route('access-governance.index') : null" />
    </div>
</div>

<x-form-errors title="That change wasn't saved." />

@foreach ($openCapacityExceptions as $exception)
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <div>
            <strong>More users than your licence covers.</strong>
            {{ $exception['active_users'] }} people are active or invited, but the licence covers {{ $exception['licensed_capacity'] }}. Nobody has been removed. Deactivate users or upgrade the licence before inviting anyone else.
        </div>
    </div>
@endforeach

@if (! $adminWritesOpen && ($canManageEmployees || $canManageRoles))
    <div class="alert alert-warning" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div class="flex-grow-1">
            @if ($reviewOverdue)
                <strong>The {{ $reviewWindow }} access review is overdue.</strong>
                Complete it in Access governance to invite people or create roles again.
            @else
                <strong>Start the {{ $reviewWindow }} access review to make changes.</strong>
                Inviting people and creating roles are paused until this quarter's access review is open.
            @endif
        </div>
        @if (! $reviewOverdue && $canStartReview)
            <form method="POST" action="{{ route('access-governance.reviews.store') }}" class="flex-shrink-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-warning">Start review</button>
            </form>
        @elseif ($user->hasAppPermission('access-governance:read'))
            <a href="{{ route('access-governance.index') }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">Open access governance</a>
        @endif
    </div>
@endif

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link @if ($activeTab === 'people') active @endif" id="people-tab" data-bs-toggle="tab" data-bs-target="#people-pane"
            type="button" role="tab" aria-controls="people-pane" aria-selected="{{ $activeTab === 'people' ? 'true' : 'false' }}">
            Team members <span class="badge rounded-pill text-bg-secondary ms-1">{{ $employees->count() }}</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link @if ($activeTab === 'roles') active @endif" id="roles-tab" data-bs-toggle="tab" data-bs-target="#roles-pane"
            type="button" role="tab" aria-controls="roles-pane" aria-selected="{{ $activeTab === 'roles' ? 'true' : 'false' }}">
            Roles <span class="badge rounded-pill text-bg-secondary ms-1">{{ $roles->count() }}</span>
        </button>
    </li>
</ul>

<div class="tab-content">
    {{-- Team members --}}
    <div class="tab-pane fade @if ($activeTab === 'people') show active @endif" id="people-pane" role="tabpanel" aria-labelledby="people-tab" tabindex="0">
        <div class="row g-3">
            <div class="{{ $canManageEmployees ? 'col-xl-8' : 'col-12' }}">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="fw-semibold">Team members</div>
                        <div class="text-muted small">
                            {{ $structures['departments'] ?? 0 }} {{ \Illuminate\Support\Str::plural('department', $structures['departments'] ?? 0) }}
                            &middot; {{ $structures['branches'] ?? 0 }} {{ \Illuminate\Support\Str::plural('branch', $structures['branches'] ?? 0) }}
                            &middot; {{ $structures['job_titles'] ?? 0 }} {{ \Illuminate\Support\Str::plural('job title', $structures['job_titles'] ?? 0) }}
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <caption class="visually-hidden">Team members, their job, last activity and status</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Job</th>
                                    <th scope="col">Last active</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($employees as $employee)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $employee['full_name'] }}</div>
                                            <div class="text-muted small">{{ $employee['email'] }} &middot; {{ $employee['employee_number'] }}</div>
                                        </td>
                                        <td>
                                            {{ $employee['job_title'] ?? 'No job title' }}
                                            @php $placement = collect([$employee['department'] ?? null, $employee['branch'] ?? null])->filter()->implode(' · '); @endphp
                                            @if ($placement !== '')
                                                <div class="text-muted small">{{ $placement }}</div>
                                            @endif
                                        </td>
                                        <td class="text-nowrap">{{ $employee['last_activity_at'] ? \Illuminate\Support\Carbon::parse($employee['last_activity_at'])->format('d M Y, H:i') : 'Not yet' }}</td>
                                        <td><x-status-badge :value="$employee['status']" type="status" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-4">No team members yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if ($canManageEmployees)
                <div class="col-xl-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="fw-semibold">Invite employee</div>
                            <div class="text-muted small">Each invitation takes up a licence seat straight away</div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('administration.employees.store') }}">
                                @csrf
                                <fieldset @disabled(! $adminWritesOpen)>
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="120" autocomplete="off" value="{{ old('full_name') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Work email</label>
                                        <input type="email" class="form-control" id="email" name="email" required autocomplete="off" placeholder="name@company.com" value="{{ old('email') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label for="employee_number" class="form-label">Employee number</label>
                                        <input type="text" class="form-control" id="employee_number" name="employee_number" required maxlength="40" placeholder="e.g. EMP-004" value="{{ old('employee_number') }}">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Invite</button>
                                    <div class="form-text">Invitations aren't emailed from this environment, so let the person know directly. You'll verify with two-factor authentication before the change is saved.</div>
                                </fieldset>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Roles --}}
    <div class="tab-pane fade @if ($activeTab === 'roles') show active @endif" id="roles-pane" role="tabpanel" aria-labelledby="roles-tab" tabindex="0">
        <div class="row g-3">
            <div class="{{ $canManageRoles ? 'col-xl-7' : 'col-12' }}">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="fw-semibold">Organisation roles</div>
                        <div class="text-muted small">Roles decide what people can do. They're separate from job titles.</div>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($roles as $role)
                            @php $codes = array_values(array_filter(array_map('trim', explode(',', (string) $role['permissions'])))); @endphp
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div class="min-w-0">
                                        <div class="fw-semibold">{{ $role['name'] }}</div>
                                        @if ($role['description'])
                                            <div class="small text-muted">{{ $role['description'] }}</div>
                                        @endif
                                    </div>
                                    <x-status-badge :value="$role['status']" type="status" />
                                </div>
                                <div class="permission-chips mt-2">
                                    @forelse ($codes as $code)
                                        <span class="permission-chip" title="{{ $code }}">{{ \App\Support\Access\PermissionLabels::label($code) }}</span>
                                    @empty
                                        <span class="small text-muted">No permissions</span>
                                    @endforelse
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-center text-muted py-4">No organisation roles yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            @if ($canManageRoles)
                <div class="col-xl-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="fw-semibold">Create organisation role</div>
                            <div class="text-muted small">Give the role only the permissions it needs</div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('administration.roles.store') }}">
                                @csrf
                                <x-idempotency-key/>
                                <fieldset @disabled(! $adminWritesOpen)>
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Role name</label>
                                        <input type="text" class="form-control" id="name" name="name" required maxlength="80" placeholder="e.g. Branch VAT reviewer" value="{{ old('name') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label for="description" class="form-label">What is this role for?</label>
                                        <input type="text" class="form-control" id="description" name="description" required maxlength="240" placeholder="e.g. Reviews branch VAT evidence" value="{{ old('description') }}">
                                    </div>
                                    <fieldset class="mb-3">
                                        <legend class="form-label mb-1">Permissions</legend>
                                        <div class="form-text mt-0 mb-2">Platform and statutory permissions can't be added to organisation roles. Hover an option for details.</div>
                                        <div class="permission-picker">
                                            @foreach ($permissionOptions as $area)
                                                <div class="permission-area">
                                                    <div class="permission-area-title">{{ $area['area'] }}</div>
                                                    @foreach ($area['resources'] as $resource)
                                                        <div class="permission-row">
                                                            <span class="permission-resource">{{ $resource['label'] }}</span>
                                                            <span class="permission-actions">
                                                                @foreach ($resource['permissions'] as $permission)
                                                                    @php $id = 'perm-'.str_replace(':', '-', $permission['code']); @endphp
                                                                    <span class="form-check form-check-inline mb-0" title="{{ $permission['description'] }}">
                                                                        <input class="form-check-input" type="checkbox" name="permissions[]" id="{{ $id }}" value="{{ $permission['code'] }}"
                                                                            @checked(in_array($permission['code'], $selectedPermissions, true))>
                                                                        <label class="form-check-label" for="{{ $id }}">{{ $permission['action'] }}</label>
                                                                    </span>
                                                                @endforeach
                                                            </span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                    <button type="submit" class="btn btn-primary">Create role</button>
                                    <div class="form-text">You'll verify with two-factor authentication before the change is saved.</div>
                                </fieldset>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

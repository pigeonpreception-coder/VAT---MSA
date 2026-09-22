@extends('layouts.app')

@section('title', 'Human resources')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operations</div>
    <h1 class="h3 mb-1">Human Resources Module</h1>
    <p class="text-muted mb-0">Employee directory, controlled onboarding invitations and offboarding. Linking an invited employee to a login identity remains an Administration action.</p>
</div>

@php
    $active = collect($employees)->where('status', 'ACTIVE')->count();
    $invited = collect($employees)->where('status', 'INVITED')->count();
    $terminated = collect($employees)->where('status', 'TERMINATED')->count();
@endphp

<div class="row row-cols-1 row-cols-sm-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Active employees</div>
            <div class="fs-2 fw-semibold">{{ number_format($active) }}</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Invited</div>
            <div class="fs-2 fw-semibold">{{ number_format($invited) }}</div>
            <div class="small text-muted">Awaiting identity activation</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Terminated</div>
            <div class="fs-2 fw-semibold">{{ number_format($terminated) }}</div>
            <div class="small text-muted">Historical records preserved</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Departments</div>
            <div class="fs-2 fw-semibold">{{ number_format((int) ($structures['departments'] ?? 0)) }}</div>
            <div class="small text-muted">{{ (int) ($structures['branches'] ?? 0) }} branches &middot; {{ (int) ($structures['job_titles'] ?? 0) }} job titles</div>
        </div></div>
    </div>
</div>

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

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Employee directory</div>
                <div class="text-muted small">Employment structure and status</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Employees, their employment details, last activity, status and available action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Employment</th>
                            <th scope="col">Last activity</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($employees as $employee)
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
                                @if ($canManage)
                                    <td>
                                        @if ($employee['status'] !== 'TERMINATED')
                                            <form method="POST" action="{{ route('operations.human-resources.employees.termination', $employee['id']) }}" onsubmit="return hrReasonPrompt(this);">
                                                @csrf
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Terminate</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Read-only history</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No employees on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        @if ($canManage)
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold">Invite an employee</div>
                    <div class="text-muted small">External email delivery remains disabled in local staging</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('operations.human-resources.employees.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="employee_number" class="form-label">Employee number</label>
                            <input type="text" class="form-control font-monospace" id="employee_number" name="employee_number" required maxlength="40" value="{{ old('employee_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="200" value="{{ old('full_name') }}">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required maxlength="254" value="{{ old('email') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Send invitation</button>
                    </form>
                </div>
            </div>
        @else
            <div class="card h-100"><div class="card-body text-muted">You have read-only access to this directory.</div></div>
        @endif
    </div>
</div>

<script>
    function hrReasonPrompt(form) {
        var reasonInput = form.querySelector('input[name=reason]');
        var reason = window.prompt('Record the termination reason.');
        if (!reason || !reason.trim()) return false;
        reasonInput.value = reason.trim();
        return true;
    }
</script>
@endsection

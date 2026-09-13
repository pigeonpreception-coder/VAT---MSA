@extends('layouts.app')

@section('title', 'Workflow engine')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Workflow engine</div>
    <h1 class="h3 mb-1">Versioned approval workflows</h1>
    <p class="text-muted mb-0">A draft is created, tested and published before it can route anything; a published version is immutable, and every decision is checked against segregation-of-duties (no self-approval).</p>
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
@if ($testResult)
    <div class="alert alert-info">
        <strong>Test result:</strong> terminal {{ $testResult['terminal'] }} &middot;
        path: {{ collect($testResult['path'])->pluck('nodeKey')->implode(' &rarr; ') }}
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Versioned workflows</div>
                <div class="text-muted small">Published versions are immutable and use typed conditions only</div>
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($workflows as $workflow)
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong>{{ $workflow['name'] }}</strong>
                            <p class="mb-0 small">{{ str_replace('_', ' ', $workflow['domain_action']) }} &middot; version {{ $workflow['version_number'] ?? 'draft' }}</p>
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
                <div class="fw-semibold">Draft versions</div>
                <div class="text-muted small">Test a draft's routing before publishing it -- publishing is permanent</div>
            </div>
            <ul class="list-group list-group-flush">
                @forelse ($draftVersions as $draft)
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div><strong>{{ $draft->workflow_name }}</strong><span class="text-muted small"> v{{ $draft->version_number }}</span></div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('workflows.publish', $draft->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Publish</button>
                                </form>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('workflows.test', $draft->id) }}" class="d-flex gap-2">
                            @csrf
                            <input type="text" name="context" class="form-control form-control-sm font-monospace" placeholder='{"amount_cents":50000}' style="width: 14rem;">
                            <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">Test routing</button>
                        </form>
                    </li>
                @empty
                    <li class="list-group-item text-center text-muted py-4">No draft versions.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

@if ($canManage)
    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold">Create a workflow draft</div>
            <div class="text-muted small">Exactly one START and one END node; an APPROVAL node needs a typed assignee (ROLE/USER/MANAGER)</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('workflows.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Workflow name</label>
                        <input type="text" class="form-control" id="name" name="name" required minlength="2" maxlength="100" value="{{ old('name') }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="domain_action" class="form-label">Domain action</label>
                        <select class="form-select" id="domain_action" name="domain_action" required>
                            <option value="" disabled selected>Select domain action</option>
                            @foreach (['PURCHASE_REQUEST', 'EXPENSE', 'JOURNAL', 'VAT_RETURN', 'ROLE_CHANGE', 'PRIMARY_ADMIN_CHANGE', 'API_CREDENTIAL', 'REFUND'] as $action)
                                <option value="{{ $action }}" @selected(old('domain_action') === $action)>{{ str_replace('_', ' ', $action) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="nodes" class="form-label">Nodes (JSON)</label>
                        <textarea class="form-control font-monospace small" id="nodes" name="nodes" rows="6">{{ old('nodes', '[
  {"id": "start", "type": "START", "label": "Start"},
  {"id": "approve", "type": "APPROVAL", "assignee_type": "MANAGER", "label": "Manager approval"},
  {"id": "end", "type": "END", "label": "End"}
]') }}</textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="transitions" class="form-label">Transitions (JSON)</label>
                        <textarea class="form-control font-monospace small" id="transitions" name="transitions" rows="6">{{ old('transitions', '[
  {"from": "start", "to": "approve"},
  {"from": "approve", "to": "end"}
]') }}</textarea>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="small text-muted text-uppercase mb-1">Roles available for a ROLE assignee_ref</div>
                        <div class="small" style="max-height: 6rem; overflow-y: auto;">
                            @forelse ($roles as $role)
                                <div><span class="font-monospace">{{ $role['id'] }}</span> &mdash; {{ $role['name'] }}</div>
                            @empty
                                <span class="text-muted">No organisation roles yet.</span>
                            @endforelse
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted text-uppercase mb-1">Members available for a USER assignee_ref</div>
                        <div class="small" style="max-height: 6rem; overflow-y: auto;">
                            @forelse ($members as $member)
                                <div><span class="font-monospace">{{ $member->id }}</span> &mdash; {{ $member->name }}</div>
                            @empty
                                <span class="text-muted">No active members yet.</span>
                            @endforelse
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Create draft</button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold">Assign a workflow instance</div>
            <div class="text-muted small">Routes a real resource through the organisation's active, published workflow for the domain action</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('workflows.assign') }}">
                @csrf
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="assign_domain_action" class="form-label">Domain action</label>
                        <select class="form-select" id="assign_domain_action" name="domain_action" required>
                            <option value="" disabled selected>Select</option>
                            @foreach (['PURCHASE_REQUEST', 'EXPENSE', 'JOURNAL', 'VAT_RETURN', 'ROLE_CHANGE', 'PRIMARY_ADMIN_CHANGE', 'API_CREDENTIAL', 'REFUND'] as $action)
                                <option value="{{ $action }}" @selected(old('domain_action') === $action)>{{ str_replace('_', ' ', $action) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="resource_type" class="form-label">Resource type</label>
                        <input type="text" class="form-control" id="resource_type" name="resource_type" required placeholder="EXPENSE_CLAIM" value="{{ old('resource_type') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="resource_id" class="form-label">Resource ID</label>
                        <input type="text" class="form-control" id="resource_id" name="resource_id" required value="{{ old('resource_id') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="assign_context" class="form-label">Context (JSON)</label>
                        <input type="text" class="form-control font-monospace" id="assign_context" name="context" placeholder='{"amount_cents":50000}'>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary">Assign instance</button>
            </form>
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Pending tasks</div>
        <div class="text-muted small">The initiator can never decide their own task; role-assigned tasks require holding that role</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Pending workflow assignments with a decide action</caption>
            <thead>
                <tr>
                    <th scope="col">Resource</th>
                    <th scope="col">Assigned to</th>
                    <th scope="col">Initiated by</th>
                    @if ($canDecide)
                        <th scope="col">Decide</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    <tr>
                        <td>{{ $task['resource_type'] }}<div class="text-muted small font-monospace">{{ $task['resource_id'] }}</div></td>
                        <td>{{ $task['assigned_to'] ?? 'Unassigned role' }}</td>
                        <td>{{ $task['initiated_by'] }}</td>
                        @if ($canDecide)
                            <td>
                                <form method="POST" action="{{ route('workflows.decide', $task['id']) }}" class="d-flex gap-2">
                                    @csrf
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (5-240 chars)" required minlength="5" maxlength="240" style="width: 12rem;">
                                    <button type="submit" name="decision" value="APPROVE" class="btn btn-sm btn-outline-success text-nowrap">Approve</button>
                                    <button type="submit" name="decision" value="REJECT" class="btn btn-sm btn-outline-danger text-nowrap">Reject</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canDecide ? 4 : 3 }}" class="text-center text-muted py-4">No pending tasks.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Delegations</div>
        <div class="text-muted small">Redirects a delegator's assigned tasks to a delegate for a bounded, effective-dated window</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Workflow delegations with a revoke action for active ones</caption>
            <thead>
                <tr>
                    <th scope="col">Delegator &rarr; delegate</th>
                    <th scope="col">Scope</th>
                    <th scope="col">Window</th>
                    <th scope="col">Status</th>
                    @if ($canManage)
                        <th scope="col">Revoke</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($delegations as $delegation)
                    <tr>
                        <td>{{ $delegation['delegator_name'] }} &rarr; {{ $delegation['delegate_name'] }}</td>
                        <td>{{ $delegation['scope'] }}{{ $delegation['workflow_name'] ? " ({$delegation['workflow_name']})" : '' }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($delegation['effective_from'])->format('d M Y') }} &ndash; {{ \Illuminate\Support\Carbon::parse($delegation['effective_to'])->format('d M Y') }}</td>
                        <td><x-status-badge :value="$delegation['status']" type="status" /></td>
                        @if ($canManage)
                            <td>
                                @if ($delegation['status'] === 'ACTIVE')
                                    <form method="POST" action="{{ route('workflows.delegations.revoke', $delegation['id']) }}" class="d-flex gap-2">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (5-240 chars)" required minlength="5" maxlength="240" style="width: 10rem;">
                                        <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No delegations on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($canManage)
        <div class="card-body border-top">
            <form method="POST" action="{{ route('workflows.delegations.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="delegator_user_id" class="form-label">Delegator</label>
                        <select class="form-select" id="delegator_user_id" name="delegator_user_id" required>
                            <option value="" disabled selected>Select member</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="delegate_user_id" class="form-label">Delegate</label>
                        <select class="form-select" id="delegate_user_id" name="delegate_user_id" required>
                            <option value="" disabled selected>Select member</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="effective_from" class="form-label">From</label>
                        <input type="datetime-local" class="form-control" id="effective_from" name="effective_from" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="effective_to" class="form-label">To</label>
                        <input type="datetime-local" class="form-control" id="effective_to" name="effective_to" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="delegation_reason" class="form-label">Reason</label>
                        <input type="text" class="form-control" id="delegation_reason" name="reason" required minlength="5" maxlength="240">
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary">Create delegation</button>
            </form>
        </div>
    @endif
</div>
@endsection

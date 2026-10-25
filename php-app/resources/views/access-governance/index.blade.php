@extends('layouts.app')

@section('title', 'Access governance')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Organisation control plane</div>
    <h1 class="h3 mb-1">Access governance</h1>
    <p class="text-muted mb-0">{{ $organisation['legal_name'] }} &middot; maker-checker access requests, quarterly certification, and on-demand revocation/offboarding.</p>
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

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Request access</div>
                <div class="text-muted small">Self or peer request for an existing organisation role -- a second reviewer decides it</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('access-governance.requests.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label for="subject_user_id" class="form-label small mb-0">For</label>
                        <select id="subject_user_id" name="subject_user_id" class="form-select form-select-sm">
                            <option value="{{ $currentUserId }}">Myself</option>
                            @foreach ($activeMembers as $member)
                                @if ($member['id'] !== $currentUserId)
                                    <option value="{{ $member['id'] }}" @selected(old('subject_user_id') === $member['id'])>{{ $member['name'] }} ({{ $member['email'] }})</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="role_id" class="form-label small mb-0">Role</label>
                        <select id="role_id" name="role_id" class="form-select form-select-sm" required>
                            <option value="" disabled selected>Choose a role&hellip;</option>
                            @foreach ($roles as $role)
                                @if ($role['status'] === 'ACTIVE')
                                    <option value="{{ $role['id'] }}" @selected(old('role_id') === $role['id'])>{{ $role['name'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="justification" class="form-label small mb-0">Justification</label>
                        <textarea id="justification" name="justification" rows="2" minlength="10" maxlength="400" required class="form-control form-control-sm" placeholder="10-400 characters">{{ old('justification') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Submit request</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Access requests</div>
                <div class="text-muted small">{{ count($accessRequests) }} on record, most recent first</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Access requests, who requested them, for which role, and their decision status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Subject</th>
                            <th scope="col">Requested by</th>
                            <th scope="col">Role</th>
                            <th scope="col">Requested</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Decision</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accessRequests as $accessRequest)
                            <tr>
                                <td>{{ $accessRequest['subject'] }}</td>
                                <td>{{ $accessRequest['requested_by'] }}</td>
                                <td>{{ $accessRequest['role_name'] }}</td>
                                <td class="text-muted small">{{ \Illuminate\Support\Carbon::parse($accessRequest['requested_at'])->format('d M Y') }}</td>
                                <td><x-status-badge :value="$accessRequest['status']" type="status" /></td>
                                @if ($canManage)
                                    <td style="min-width: 200px;">
                                        @if ($accessRequest['status'] === 'PENDING_MANAGER')
                                            {{-- Step-up gated: routes/web.php applies 'step-up' to this
                                                 route, matching the JSON API's own
                                                 /access-requests/{id}/decision POST. A requester or
                                                 subject deciding their own request is rejected
                                                 server-side (AccessGovernanceService's own
                                                 SELF_APPROVAL_DENIED guard) -- the list here has no
                                                 subject/requester user id to pre-filter the control on,
                                                 only their names, so this shows for every pending row. --}}
                                            <form method="POST" action="{{ route('access-governance.requests.decision', $accessRequest['id']) }}">
                                                @csrf
                                                <textarea name="reason" rows="1" minlength="5" maxlength="240" required placeholder="Reason" class="form-control form-control-sm mb-1"></textarea>
                                                <div class="d-flex gap-1">
                                                    <button type="submit" name="decision" value="APPROVE" class="btn btn-success btn-sm flex-fill">Approve</button>
                                                    <button type="submit" name="decision" value="REJECT" class="btn btn-outline-danger btn-sm flex-fill">Reject</button>
                                                </div>
                                            </form>
                                        @else
                                            <span class="text-muted small">&mdash;</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="text-center text-muted py-4">No access requests have been submitted.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Quarterly access reviews</div>
        <div class="text-muted small">A review completes automatically once every active member has a certification</div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Quarterly access reviews and their status</caption>
            <thead>
                <tr>
                    <th scope="col">Review</th>
                    <th scope="col">Type</th>
                    <th scope="col">Due</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accessReviews as $review)
                    <tr>
                        <td>{{ $review['name'] }}</td>
                        <td>{{ ucwords(strtolower(str_replace('_', ' ', $review['review_type']))) }}</td>
                        <td class="text-muted small">{{ \Illuminate\Support\Carbon::parse($review['due_at'])->format('d M Y') }}</td>
                        <td><x-status-badge :value="$review['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">No access reviews on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($canManage)
        <div class="card-footer">
            {{-- Step-up gated, matching the JSON API's own
                 /access-reviews/{id}/certifications POST. review_id is typed
                 in directly (the table above lists each review's name/type/
                 due date, not its id) -- see this page's own doc comment
                 for why extending the shared snapshot's row shape was kept
                 out of this pass. --}}
            <form method="POST" action="{{ route('access-governance.certifications.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label for="review_id" class="form-label small mb-0">Review ID</label>
                    <input type="text" id="review_id" name="review_id" required class="form-control form-control-sm font-monospace" value="{{ old('review_id') }}">
                </div>
                <div class="col-md-3">
                    <label for="cert_subject_user_id" class="form-label small mb-0">Member</label>
                    <select id="cert_subject_user_id" name="subject_user_id" class="form-select form-select-sm" required>
                        <option value="" disabled selected>Choose&hellip;</option>
                        @foreach ($activeMembers as $member)
                            <option value="{{ $member['id'] }}" @selected(old('subject_user_id') === $member['id'])>{{ $member['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="disposition" class="form-label small mb-0">Disposition</label>
                    <select id="disposition" name="disposition" class="form-select form-select-sm" required>
                        <option value="RETAIN">Retain</option>
                        <option value="REVOKE">Revoke</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="finding" class="form-label small mb-0">Finding (optional)</label>
                    <input type="text" id="finding" name="finding" maxlength="400" class="form-control form-control-sm" value="{{ old('finding') }}">
                </div>
                <div class="col-md-1 align-self-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Certify</button>
                </div>
            </form>
        </div>
    @endif
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Active role grants</div>
                <div class="text-muted small">{{ count($roleGrants) }} active</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Active organisation role grants, revocable individually</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            @if ($canManage)
                                <th scope="col">Revoke</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roleGrants as $grant)
                            <tr>
                                <td>{{ $grant['user_name'] }}</td>
                                <td>{{ $grant['role_name'] }}</td>
                                @if ($canManage)
                                    <td>
                                        {{-- Step-up gated, matching /access-grants/revocation. --}}
                                        <form method="POST" action="{{ route('access-governance.revocations.store') }}" class="d-flex gap-1">
                                            @csrf
                                            <input type="hidden" name="grant_type" value="ROLE">
                                            <input type="hidden" name="grant_id" value="{{ $grant['id'] }}">
                                            <input type="text" name="reason" minlength="5" maxlength="240" required placeholder="Reason" class="form-control form-control-sm">
                                            <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Revoke</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 3 : 2 }}" class="text-center text-muted py-3">No active role grants.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Active capability grants</div>
                <div class="text-muted small">{{ count($capabilityGrants) }} active</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Active user capability grants, revocable individually</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Capability</th>
                            @if ($canManage)
                                <th scope="col">Revoke</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($capabilityGrants as $grant)
                            <tr>
                                <td>{{ $grant['user_name'] }}</td>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $grant['capability']))) }}</td>
                                @if ($canManage)
                                    <td>
                                        <form method="POST" action="{{ route('access-governance.revocations.store') }}" class="d-flex gap-1">
                                            @csrf
                                            <input type="hidden" name="grant_type" value="CAPABILITY">
                                            <input type="hidden" name="grant_id" value="{{ $grant['id'] }}">
                                            <input type="text" name="reason" minlength="5" maxlength="240" required placeholder="Reason" class="form-control form-control-sm">
                                            <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Revoke</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 3 : 2 }}" class="text-center text-muted py-3">No active capability grants.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if ($canManage)
    <div class="card border-danger">
        <div class="card-header text-danger">Offboard a user</div>
        <div class="card-body">
            <p class="text-muted small">Immediately revokes this user's organisation membership and every active role/capability grant. For a security incident or an access-only exit -- employment and licence seat records are untouched. Idempotent: safe to submit again.</p>
            {{-- Step-up gated, matching /organisations/offboarding. --}}
            <form method="POST" action="{{ route('access-governance.offboarding.store') }}" class="row g-2">
                @csrf
                <div class="col-md-5">
                    <label for="offboard_user_id" class="form-label small mb-0">User</label>
                    <select id="offboard_user_id" name="user_id" class="form-select form-select-sm" required>
                        <option value="" disabled selected>Choose&hellip;</option>
                        @foreach ($activeMembers as $member)
                            @if ($member['id'] !== $currentUserId)
                                <option value="{{ $member['id'] }}" @selected(old('user_id') === $member['id'])>{{ $member['name'] }} ({{ $member['email'] }})</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="offboard_reason" class="form-label small mb-0">Reason</label>
                    <input type="text" id="offboard_reason" name="reason" minlength="5" maxlength="240" required class="form-control form-control-sm" value="{{ old('reason') }}">
                </div>
                <div class="col-md-2 align-self-end">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Offboard</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection

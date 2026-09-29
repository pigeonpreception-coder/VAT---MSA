@extends('layouts.app')

@section('title', 'Access rights')

@php
    $sidePreviewLimit = 5;
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Super Administration</div>
    <h1 class="h3 mb-1">User access rights</h1>
    <p class="text-muted mb-0">Grant a user one of the app's roles at a Local Office, Regional/Provincial, National or Global scope. Granting a role immediately updates the user's own role; scope level and office/region are recorded as governance context alongside it.</p>
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
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Grants</span><span>N</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['total']) }}</div>
                <div class="small text-muted">Most recent 100</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active</span><span>&check;</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['active']) }}</div>
                <div class="small text-muted">Currently in effect</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Revoked</span><span>&times;</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['revoked']) }}</div>
                <div class="small text-muted">No longer in effect</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Users</span><span>U</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($metrics['users']) }}</div>
                <div class="small text-muted">System-wide</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        @if ($canManage)
            <div class="card mb-3">
                <div class="card-header">
                    <div class="fw-semibold">Grant an access right</div>
                    <div class="text-muted small">You cannot grant yourself an access right</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('access-rights.store') }}">
                        @csrf
                        <x-idempotency-key/>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="user_id" class="form-label">User</label>
                                <select class="form-select" id="user_id" name="user_id" required>
                                    <option value="" disabled selected>Select user</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected(old('user_id') === $user->id)>{{ $user->name }} ({{ $user->email }}) &mdash; {{ str_replace('_', ' ', $user->role) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="role_code" class="form-label">Role</label>
                                <select class="form-select" id="role_code" name="role_code" required>
                                    <option value="" disabled selected>Select role</option>
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->code }}" @selected(old('role_code') === $role->code)>{{ $role->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="scope_level" class="form-label">Scope level</label>
                                <select class="form-select" id="scope_level" name="scope_level" required onchange="document.getElementById('scope_label').dispatchEvent(new Event('vat-msa:scope-changed'))">
                                    <option value="" disabled selected>Select scope</option>
                                    <option value="LOCAL_OFFICE" @selected(old('scope_level') === 'LOCAL_OFFICE')>Local Office</option>
                                    <option value="REGIONAL" @selected(old('scope_level') === 'REGIONAL')>Regional / Provincial</option>
                                    <option value="NATIONAL" @selected(old('scope_level') === 'NATIONAL')>National</option>
                                    <option value="GLOBAL" @selected(old('scope_level') === 'GLOBAL')>Global</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="scope_label" class="form-label">Office / Region name</label>
                                <input type="text" class="form-control" id="scope_label" name="scope_label" maxlength="120" placeholder="e.g. Windhoek, Khomas Region" value="{{ old('scope_label') }}">
                                <div class="form-text">A free-text name, required for Local Office/Regional scope; ignored for National/Global.</div>
                            </div>
                        </div>
                        <div class="form-text mb-3">Granting a role is a privileged action and requires re-confirming your password.</div>
                        <button type="submit" class="btn btn-primary">Grant access right</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Access grants</div>
                <div class="text-muted small">Most recent 100 grants, newest first</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">User access grants with their role, scope, office/region, who granted them, when and their status</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role &amp; scope</th>
                            <th scope="col">Granted</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Revoke</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grants as $grant)
                            <tr>
                                <td>{{ $grant->user->name ?? 'Unknown user' }}<div class="text-muted small">{{ $grant->user->email ?? '' }}</div></td>
                                <td>
                                    <span class="font-monospace">{{ $grant->role_code }}</span>
                                    <div class="text-muted small">{{ str_replace('_', ' ', $grant->scope_level) }}{{ $grant->scope_label ? ' · '.$grant->scope_label : '' }}</div>
                                </td>
                                <td class="small">
                                    {{ $grant->granted_at?->format('d M Y, H:i') }}
                                    <div class="text-muted">by {{ $grant->grantedBy->name ?? 'Unknown user' }}</div>
                                </td>
                                <td><x-status-badge :value="$grant->status" type="status" /></td>
                                @if ($canManage)
                                    <td>
                                        @if ($grant->status === 'ACTIVE')
                                            <form method="POST" action="{{ route('access-rights.revoke', $grant) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No access grants yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">Grants by role</div>
            <div class="card-body">
                @forelse ($byRole as $index => $row)
                    <div class="mb-2 d-flex justify-content-between align-items-center by-role-item" @if ($index >= $sidePreviewLimit) hidden @endif>
                        <span class="font-monospace small">{{ $row['role_code'] }}</span>
                        <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No access grants recorded yet.</p>
                @endforelse
                @if (count($byRole) > $sidePreviewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="by-role-item" data-show-more-count="{{ count($byRole) - $sidePreviewLimit }}">
                        Show {{ count($byRole) - $sidePreviewLimit }} more
                    </button>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Grants by scope</div>
            <div class="card-body">
                @forelse ($byScope as $row)
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <span>{{ str_replace('_', ' ', $row['scope_level']) }}</span>
                        <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No access grants recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

@if ($canManage)
    <script>
        (function () {
            var scopeSelect = document.getElementById('scope_level');
            var labelInput = document.getElementById('scope_label');

            function syncLabelRequired() {
                var scope = scopeSelect.value;
                labelInput.required = scope === 'LOCAL_OFFICE' || scope === 'REGIONAL';
            }

            scopeSelect.addEventListener('change', syncLabelRequired);
            labelInput.addEventListener('vat-msa:scope-changed', syncLabelRequired);
            syncLabelRequired();
        })();
    </script>
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

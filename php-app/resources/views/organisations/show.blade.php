@extends('layouts.app')

@section('title', $organisation->legal_name)

@section('content')
<div class="mb-3">
    <a href="{{ route('organisations.index') }}" class="small">&larr; Back to organisations</a>
</div>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Organisation</div>
        <h1 class="h3 mb-1">{{ $organisation->legal_name }}</h1>
        <p class="text-muted mb-0">
            {{ $organisation->taxpayer?->vat_number }} &middot; {{ $organisation->taxpayer?->tin }}
            &middot; <x-status-badge :value="$organisation->taxpayer?->vat_status" type="taxpayer" />
        </p>
    </div>
    <x-status-badge :value="$organisation->status" type="status" />
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

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Branches</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Branches of this organisation</caption>
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">Name</th>
                            <th scope="col">Address</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($organisation->branches as $branch)
                            <tr>
                                <td class="font-monospace">{{ $branch->code }}{{ $branch->is_head_office ? ' (HQ)' : '' }}</td>
                                <td>{{ $branch->name }}</td>
                                <td>{{ $branch->address }}</td>
                                <td><x-status-badge :value="$branch->status" type="status" /></td>
                                @if ($canManage)
                                    <td>
                                        @unless ($branch->is_head_office && $branch->status === 'ACTIVE')
                                            <form method="POST" action="{{ route('organisations.branches.update', [$organisation->id, $branch->id]) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $branch->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }}">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">{{ $branch->status === 'ACTIVE' ? 'Deactivate' : 'Reactivate' }}</button>
                                            </form>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canManage)
                <div class="card-footer">
                    <form method="POST" action="{{ route('organisations.branches.store', $organisation->id) }}" class="row g-2">
                        @csrf
                        <div class="col-md-2">
                            <label for="code" class="form-label small mb-0">Code</label>
                            <input type="text" id="code" name="code" value="{{ old('code') }}" class="form-control form-control-sm @error('code') is-invalid @enderror" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label for="name" class="form-label small mb-0">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control form-control-sm @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label for="address" class="form-label small mb-0">Address</label>
                            <input type="text" id="address" name="address" value="{{ old('address') }}" class="form-control form-control-sm @error('address') is-invalid @enderror" required>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2 align-self-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Add branch</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        <div class="card">
            <div class="card-header">Memberships</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Staff memberships of this organisation</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Branch</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($organisation->memberships as $membership)
                            <tr>
                                <td>{{ $membership->user?->name }} <div class="text-muted small">{{ $membership->user?->email }}</div></td>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $membership->role_code))) }}</td>
                                <td>{{ $organisation->branches->firstWhere('id', $membership->branch_id)?->name ?? '—' }}</td>
                                <td><x-status-badge :value="$membership->status" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No memberships yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($canManage)
                <div class="card-footer">
                    {{-- Step-up gated: routes/web.php applies 'password.confirm'
                         to this route, matching the JSON API's own
                         /organisations/{id}/memberships POST. --}}
                    <form method="POST" action="{{ route('organisations.memberships.store', $organisation->id) }}" class="row g-2">
                        @csrf
                        <div class="col-md-3">
                            <label for="email" class="form-label small mb-0">User email</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control form-control-sm @error('email') is-invalid @enderror" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="role_code" class="form-label small mb-0">Role</label>
                            <select id="role_code" name="role_code" class="form-select form-select-sm @error('role_code') is-invalid @enderror" required>
                                @foreach ($assignableRoles as $role)
                                    <option value="{{ $role }}" @selected(old('role_code') === $role)>{{ ucwords(strtolower(str_replace('_', ' ', $role))) }}</option>
                                @endforeach
                            </select>
                            @error('role_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="branch_id" class="form-label small mb-0">Branch (optional)</label>
                            <select id="branch_id" name="branch_id" class="form-select form-select-sm">
                                <option value="">No specific branch</option>
                                @foreach ($organisation->branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('branch_id') === $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 align-self-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Assign membership</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Trading capabilities</div>
            <ul class="list-group list-group-flush">
                @forelse ($organisation->capabilities as $capability)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        {{ ucwords(strtolower($capability->capability)) }}
                        <x-status-badge :value="$capability->status" type="status" />
                    </li>
                @empty
                    <li class="list-group-item text-muted small">No trading capabilities granted.</li>
                @endforelse
            </ul>
        </div>

        @if ($canSuspend)
            <div class="card border-danger">
                <div class="card-header text-danger">Taxpayer suspension</div>
                <div class="card-body">
                    @if ($organisation->taxpayer?->vat_status === 'SUSPENDED')
                        <p class="text-muted small mb-0">This taxpayer is already suspended.</p>
                    @else
                        <p class="text-muted small">Suspending flips this taxpayer's VAT status platform-wide. This is a sensitive, step-up gated action.</p>
                        <form method="POST" action="{{ route('organisations.taxpayer-suspension.store', $organisation->id) }}">
                            @csrf
                            <input type="hidden" name="taxpayer_id" value="{{ $organisation->taxpayer_id }}">
                            <div class="mb-2">
                                <label for="reason" class="form-label small mb-0">Reason</label>
                                <textarea id="reason" name="reason" minlength="5" maxlength="240" rows="2" required class="form-control form-control-sm @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">Suspend taxpayer</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

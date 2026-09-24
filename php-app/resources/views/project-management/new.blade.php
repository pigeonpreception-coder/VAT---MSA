@extends('layouts.app')

@section('title', 'Create New Project')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Project Management</div>
        <h1 class="h3 mb-1">Create New Project</h1>
        <p class="text-muted mb-0">Every planned project awaiting activation. Once a project starts, activate it here to move it onto the Ongoing Project Reports page.</p>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">Planned projects</div>
                <div class="text-muted small">{{ number_format($planned->count()) }} awaiting activation</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Planned projects, their customer, dates and activation action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Project</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Dates</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($planned as $project)
                            <tr>
                                <td>
                                    <strong>{{ $project->name }}</strong>
                                    <div class="text-muted small font-monospace">{{ $project->code }}</div>
                                </td>
                                <td>{{ optional($project->customer)->display_name ?? '—' }}</td>
                                <td>
                                    {{ $project->start_date->toDateString() }}
                                    @if ($project->end_date)
                                        <div class="text-muted small">to {{ $project->end_date->toDateString() }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @can('permission', 'projects:manage')
                                        <form method="POST" action="{{ route('project-management.activate', $project->id) }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <button type="submit" class="btn btn-sm btn-primary">Activate</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No planned projects on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">New project</div>
                <div class="text-muted small">Starts in Planned status until activated above</div>
            </div>
            <div class="card-body">
                @can('permission', 'projects:manage')
                    <form method="POST" action="{{ route('project-management.store') }}">
                        @csrf
                        <x-idempotency-key />
                        <div class="mb-3">
                            <label for="code" class="form-label">Project code</label>
                            <input type="text" class="form-control font-monospace" id="code" name="code" required maxlength="40" placeholder="PROJ-2026-0001" value="{{ old('code') }}">
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label">Project name</label>
                            <input type="text" class="form-control" id="name" name="name" required maxlength="200" value="{{ old('name') }}">
                        </div>
                        <div class="mb-3">
                            <label for="customer_party_id" class="form-label">Customer (optional)</label>
                            <select class="form-select" id="customer_party_id" name="customer_party_id">
                                <option value="">No customer</option>
                                @foreach ($customers as $party)
                                    <option value="{{ $party['id'] }}" @selected(old('customer_party_id') === $party['id'])>{{ $party['display_name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="start_date" class="form-label">Start date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" required value="{{ old('start_date') }}">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="end_date" class="form-label">End date (optional)</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="{{ old('end_date') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="budget_cents" class="form-label">Proposed budget (cents, optional)</label>
                            <input type="number" class="form-control" id="budget_cents" name="budget_cents" min="0" step="1" value="{{ old('budget_cents') }}">
                            <div class="form-text">Enter {{ $tenantCurrencySymbol }} 100.00 as 10000. Awaits approval on the Budgets page.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Create project</button>
                    </form>
                @else
                    <p class="text-muted mb-0">You do not have permission to create a project.</p>
                @endcan
            </div>
        </div>
    </div>
</div>
@endsection

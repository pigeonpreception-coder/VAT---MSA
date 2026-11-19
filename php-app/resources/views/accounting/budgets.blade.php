@extends('layouts.app')

@section('title', 'Budgets')

@php
    $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
@endphp

@section('content')
<x-page-header title="Budgets"
    subtitle="Proposed and approved project budgets against actual posted costs. A proposed budget needs approval from someone other than the project manager before it counts." />

<x-form-errors />

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Proposed</div>
            <div class="stat-value">{{ $fmt($totals['proposed_cents']) }}</div>
            <div class="small text-muted">Across all projects</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Approved</div>
            <div class="stat-value">{{ $fmt($totals['approved_cents']) }}</div>
            <div class="small text-success">Counts toward variance</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Actual cost</div>
            <div class="stat-value">{{ $fmt($totals['cost_cents']) }}</div>
            <div class="small text-muted">Posted project costs</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Variance</div>
            <div class="fs-2 fw-semibold {{ $totals['variance_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $fmt($totals['variance_cents']) }}</div>
            <div class="small text-muted">Approved minus actual cost</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Project budgets</div>
        <div class="text-muted small">Variance turns negative once actual cost exceeds the approved budget</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Project budgets, proposed and approved amounts, actual cost and variance</caption>
            <thead>
                <tr>
                    <th scope="col">Project</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Budget status</th>
                    <th scope="col" class="text-end">Proposed</th>
                    <th scope="col" class="text-end">Approved</th>
                    <th scope="col" class="text-end">Actual cost</th>
                    <th scope="col" class="text-end">Variance</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                    <tr>
                        <td>
                            <strong>{{ $project['name'] }}</strong>
                            <div class="text-muted small font-monospace">{{ $project['code'] }}</div>
                        </td>
                        <td>{{ $project['customer_name'] ?? '—' }}</td>
                        <td>
                            @if ($project['budget_status'])
                                <x-status-badge :value="$project['budget_status']" type="status" />
                            @else
                                <span class="text-muted small">No budget proposed</span>
                            @endif
                        </td>
                        <td class="text-end">{{ $fmt($project['proposed_cents']) }}</td>
                        <td class="text-end fw-semibold">{{ $fmt($project['approved_cents']) }}</td>
                        <td class="text-end">{{ $project['currency'] }} {{ number_format($project['cost_cents'] / 100, 2) }}</td>
                        <td class="text-end {{ $project['variance_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $fmt($project['variance_cents']) }}</td>
                        <td class="text-end">
                            @can('permission', 'projects:manage')
                                @if ($project['budget_status'] === 'PROPOSED')
                                    <form method="POST" action="{{ route('accounting.budgets.approval', $project['id']) }}" class="d-flex gap-1 justify-content-end">
                                        @csrf
                                        <x-idempotency-key />
                                        <x-money-input name="approved_amount_cents" :id="'approved-'.$project['id']" :value="$project['proposed_cents']" size="sm" :required="true" style="width: 8rem" aria-label="Approved amount for {{ $project['name'] ?? 'this project' }}" />
                                        <button type="submit" class="btn btn-success btn-sm text-nowrap">Approve</button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No projects on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

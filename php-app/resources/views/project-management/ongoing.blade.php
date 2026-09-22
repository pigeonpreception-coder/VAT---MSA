@extends('layouts.app')

@section('title', 'Ongoing Project Reports')

@php
    $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Project Management</div>
        <h1 class="h3 mb-1">Ongoing Project Reports</h1>
        <p class="text-muted mb-0">Every active project's approved budget, posted cost, revenue recognised so far and running profit. Mark a project completed once its work has finished.</p>
    </div>
    <a href="{{ route('project-management.new') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Planned projects</a>
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

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Active projects</div>
        <div class="text-muted small">{{ number_format(count($projects)) }} in progress</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Active projects, their customer, approved budget, revenue, cost and profit to date</caption>
            <thead>
                <tr>
                    <th scope="col">Project</th>
                    <th scope="col">Customer</th>
                    <th scope="col" class="text-end">Approved budget</th>
                    <th scope="col" class="text-end">Revenue to date</th>
                    <th scope="col" class="text-end">Cost to date</th>
                    <th scope="col" class="text-end">Profit to date</th>
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
                        <td class="text-end">{{ $fmt($project['approved_budget_cents']) }}</td>
                        <td class="text-end">{{ $fmt($project['revenue_cents']) }}</td>
                        <td class="text-end">{{ $fmt($project['cost_cents']) }}</td>
                        <td class="text-end {{ $project['profit_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $fmt($project['profit_cents']) }}</td>
                        <td class="text-end">
                            @can('permission', 'projects:manage')
                                <form method="POST" action="{{ route('project-management.complete', $project['id']) }}">
                                    @csrf
                                    <x-idempotency-key />
                                    <button type="submit" class="btn btn-sm btn-outline-success">Mark completed</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No active projects on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

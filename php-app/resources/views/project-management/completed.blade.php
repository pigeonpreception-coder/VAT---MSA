@extends('layouts.app')

@section('title', 'Completed Projects')

@php
    $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Project Management</div>
        <h1 class="h3 mb-1">Completed Projects</h1>
        <p class="text-muted mb-0">A read-only summary of every finished project's final budget, revenue, cost and profit.</p>
    </div>
    <a href="{{ route('project-management.ongoing') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Ongoing projects</a>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Completed projects</div>
        <div class="text-muted small">{{ number_format(count($projects)) }} finished</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Completed projects, their customer, final budget, revenue, cost and profit</caption>
            <thead>
                <tr>
                    <th scope="col">Project</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Dates</th>
                    <th scope="col" class="text-end">Approved budget</th>
                    <th scope="col" class="text-end">Revenue</th>
                    <th scope="col" class="text-end">Cost</th>
                    <th scope="col" class="text-end">Profit</th>
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
                            {{ $project['start_date'] }}
                            @if ($project['end_date'])
                                <div class="text-muted small">to {{ $project['end_date'] }}</div>
                            @endif
                        </td>
                        <td class="text-end">{{ $fmt($project['approved_budget_cents']) }}</td>
                        <td class="text-end">{{ $fmt($project['revenue_cents']) }}</td>
                        <td class="text-end">{{ $fmt($project['cost_cents']) }}</td>
                        <td class="text-end {{ $project['profit_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $fmt($project['profit_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No completed projects on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

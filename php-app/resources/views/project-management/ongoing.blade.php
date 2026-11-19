@extends('layouts.app')

@section('title', 'Ongoing projects')

@php
    $fmt = fn (int $cents) => $tenantCurrencySymbol.' '.number_format($cents / 100, 2);
@endphp

@section('content')
<x-page-header title="Ongoing projects"
    subtitle="Budget, posted cost, revenue and running profit for every active project. Mark a project completed when its work is done.">
    <x-slot:actions>
        <a href="{{ route('project-management.new') }}" class="btn btn-outline-secondary btn-sm">Planned projects</a>
    </x-slot:actions>
</x-page-header>

<x-form-errors />

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

@extends('layouts.app')

@section('title', 'Cash Flow Projects')

@php
    $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Accounting & Finance</div>
        <h1 class="h3 mb-1">Cash Flow Projects</h1>
        <p class="text-muted mb-0">Real posted revenue and cost per project, organisation-wide -- monitoring what has actually been posted to date, not a projection: this platform has no forecast data to derive one from.</p>
    </div>
    <a href="{{ route('accounting.index') }}" class="btn btn-outline-secondary btn-sm text-nowrap ms-3">Back to Accounting</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Approved budget</div>
            <div class="fs-2 fw-semibold">{{ $fmt($totals['approved_budget_cents']) }}</div>
            <div class="small text-muted">Across all projects</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Revenue posted</div>
            <div class="fs-2 fw-semibold">{{ $fmt($totals['revenue_cents']) }}</div>
            <div class="small text-success">Project-tagged REVENUE journal lines</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Cost posted</div>
            <div class="fs-2 fw-semibold">{{ $fmt($totals['cost_cents']) }}</div>
            <div class="small text-muted">Approved expenses and manual entries</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Net cash flow</div>
            <div class="fs-2 fw-semibold {{ $totals['net_cash_flow_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $fmt($totals['net_cash_flow_cents']) }}</div>
            <div class="small text-muted">Revenue minus cost</div>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="fw-semibold">Project cash flow</div>
        <div class="text-muted small">Net cash flow = revenue posted minus cost posted; a negative figure means the project has cost more than it has earned so far</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Projects, their approved budget, posted revenue, posted cost and net cash flow</caption>
            <thead>
                <tr>
                    <th scope="col">Project</th>
                    <th scope="col">Customer</th>
                    <th scope="col" class="text-end">Approved budget</th>
                    <th scope="col" class="text-end">Revenue</th>
                    <th scope="col" class="text-end">Cost</th>
                    <th scope="col" class="text-end">Net cash flow</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                    <tr class="{{ $selectedProjectId === $project['id'] ? 'table-active' : '' }}">
                        <td>
                            <strong>{{ $project['name'] }}</strong>
                            <div class="text-muted small font-monospace">{{ $project['code'] }}</div>
                        </td>
                        <td>{{ $project['customer_name'] ?? '—' }}</td>
                        <td class="text-end">{{ $project['currency'] }} {{ number_format($project['approved_budget_cents'] / 100, 2) }}</td>
                        <td class="text-end text-success">{{ $project['currency'] }} {{ number_format($project['revenue_cents'] / 100, 2) }}</td>
                        <td class="text-end">{{ $project['currency'] }} {{ number_format($project['cost_cents'] / 100, 2) }}</td>
                        <td class="text-end fw-semibold {{ $project['net_cash_flow_cents'] < 0 ? 'text-danger' : 'text-success' }}">{{ $project['currency'] }} {{ number_format($project['net_cash_flow_cents'] / 100, 2) }}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('accounting.cash-flow', ['project_id' => $project['id']]) }}">View cost timeline</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No projects on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($timeline)
    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">{{ $timeline['project']['name'] }} -- cost timeline</div>
            <div class="text-muted small font-monospace">{{ $timeline['project']['code'] }}</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Costs posted against this project over time, with a cumulative total</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Type</th>
                        <th scope="col">Description</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Cumulative cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($timeline['lines'] as $line)
                        <tr>
                            <td>{{ $line['occurred_at'] }}</td>
                            <td>{{ ucfirst(strtolower($line['cost_type'])) }}</td>
                            <td>{{ $line['description'] ?? '—' }}</td>
                            <td class="text-end">{{ $timeline['project']['currency'] }} {{ number_format($line['amount_cents'] / 100, 2) }}</td>
                            <td class="text-end fw-semibold">{{ $timeline['project']['currency'] }} {{ number_format($line['cumulative_cost_cents'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No costs have been posted against this project.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

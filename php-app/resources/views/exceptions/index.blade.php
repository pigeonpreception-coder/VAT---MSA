@extends('layouts.app')

@section('title', 'Reconciliation exceptions')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">VAT management</div>
    <h1 class="h3 mb-1">Reconciliation exceptions</h1>
    <p class="text-muted mb-0">The reconciliation matching engine's work queue -- invoices whose ledger postings did not tie out against their own declared figures, awaiting officer review.</p>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Open exceptions</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($summary['open_count']) }}</div>
                <div class="small text-muted">Awaiting controlled review</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Critical severity</span><span>C</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($summary['critical_count']) }}</div>
                <div class="small text-warning">Prioritised for officer attention</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Exception value</span><span>{{ $tenantCurrencySymbol }}</span></div>
                <div class="fs-2 fw-semibold">{{ $tenantCurrencySymbol }} {{ number_format($summary['total_value_cents'] / 100, 2) }}</div>
                <div class="small text-muted">Gross value under exception control</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Resolution policy</span><span>2</span></div>
                <div class="fs-2 fw-semibold">Dual</div>
                <div class="small text-muted">High-impact closure requires approval</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('exceptions.index') }}" class="row g-2">
            <div class="col-md-2">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="OPEN" @selected(($filters['status'] ?? null) === 'OPEN')>Open</option>
                    <option value="ASSIGNED" @selected(($filters['status'] ?? null) === 'ASSIGNED')>Assigned</option>
                    <option value="RESOLVED" @selected(($filters['status'] ?? null) === 'RESOLVED')>Resolved</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="severity" class="form-label small mb-0">Severity</label>
                <select id="severity" name="severity" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="LOW" @selected(($filters['severity'] ?? null) === 'LOW')>Low</option>
                    <option value="MEDIUM" @selected(($filters['severity'] ?? null) === 'MEDIUM')>Medium</option>
                    <option value="HIGH" @selected(($filters['severity'] ?? null) === 'HIGH')>High</option>
                    <option value="CRITICAL" @selected(($filters['severity'] ?? null) === 'CRITICAL')>Critical</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="unassigned_only" class="form-label small mb-0">Assignment</label>
                <select id="unassigned_only" name="unassigned_only" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="true" @selected(($filters['unassigned_only'] ?? null) === 'true')>Unassigned only</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="min_age_days" class="form-label small mb-0">Min age (days)</label>
                <input type="number" min="0" id="min_age_days" name="min_age_days" value="{{ $filters['min_age_days'] ?? '' }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="max_age_days" class="form-label small mb-0">Max age (days)</label>
                <input type="number" min="0" id="max_age_days" name="max_age_days" value="{{ $filters['max_age_days'] ?? '' }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="text-muted small">{{ $workQueue['total_count'] }} exception{{ $workQueue['total_count'] === 1 ? '' : 's' }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Reconciliation exception work queue</caption>
            <thead>
                <tr>
                    <th scope="col">Invoice</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Status</th>
                    <th scope="col">Age</th>
                    <th scope="col">Assigned to</th>
                    <th scope="col">Summary</th>
                    @if ($canManage)
                        <th scope="col">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($workQueue['items'] as $item)
                    <tr>
                        <td>
                            <strong>{{ $item['invoice_number'] }}</strong>
                            <div class="text-muted small">{{ $item['supplier_name'] }}</div>
                        </td>
                        <td><x-status-badge :value="$item['severity']" type="risk" /></td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                        <td>{{ $item['age_days'] }}d</td>
                        <td>{{ $item['assigned_officer_name'] ?? '—' }}</td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($item['summary'], 120) }}</td>
                        @if ($canManage)
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    @if ($item['status'] !== 'RESOLVED')
                                        <form method="POST" action="{{ route('exceptions.assignment.store', $item['id']) }}" class="d-flex gap-1">
                                            @csrf
                                            <x-idempotency-key />
                                            <input type="text" name="officer_id" class="form-control form-control-sm" placeholder="Officer user ID" required>
                                            <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Assign</button>
                                        </form>
                                        <form method="POST" action="{{ route('exceptions.resolution.store', $item['id']) }}" class="d-flex gap-1">
                                            @csrf
                                            <x-idempotency-key />
                                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Resolution notes (10-400 chars)" minlength="10" maxlength="400" required>
                                            <button type="submit" class="btn btn-outline-success btn-sm text-nowrap">Resolve</button>
                                        </form>
                                    @else
                                        <span class="text-muted small">Resolved</span>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 7 : 6 }}" class="text-center text-muted py-4">No reconciliation exceptions match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

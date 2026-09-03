@extends('layouts.app')

@section('title', 'Business operations')

@php
    $quantity = fn ($micros) => number_format((float) $micros / 1_000_000, 6);
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operational domains</div>
    <h1 class="h3 mb-1">Expenses, inventory and projects</h1>
    <p class="text-muted mb-0">Operational evidence remains linked to the organisation, branch, project and source document so VAT treatment and accounting postings can be reconstructed rather than inferred.</p>
</div>

<div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Expenses</div>
            <div class="fs-2 fw-semibold">{{ number_format($expenses->count()) }}</div>
            <div class="small text-muted">NAD {{ number_format($expenseValueCents / 100, 2) }} recorded</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Inventory items</div>
            <div class="fs-2 fw-semibold">{{ number_format($balances->count()) }}</div>
            <div class="small text-muted">Non-negative database invariant</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Active projects</div>
            <div class="fs-2 fw-semibold">{{ number_format($projects->count()) }}</div>
            <div class="small text-muted">Budget-to-cost traceability</div>
        </div></div>
    </div>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
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

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Expense register</div>
                <div class="text-muted small">Clean receipt evidence and independent maker-checker decisions are enforced before approval</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Expenses, their category, supplier, amount, status, receipt evidence and available decision</caption>
                    <thead>
                        <tr>
                            <th scope="col">Expense</th>
                            <th scope="col">Date</th>
                            <th scope="col">Category / supplier</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Receipt evidence</th>
                            <th scope="col">Independent review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expenses as $expense)
                            <tr>
                                <td>
                                    <strong>{{ $expense['expense_number'] }}</strong>
                                    <div class="text-muted small">{{ $expense['description'] }}</div>
                                </td>
                                <td>{{ $expense['expense_date'] }}</td>
                                <td>
                                    {{ $expense['category_name'] }}
                                    <div class="text-muted small">{{ $expense['supplier_name'] ?? 'No supplier' }}</div>
                                </td>
                                <td>{{ $expense['currency'] }} {{ number_format($expense['total_cents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$expense['status']" type="status" /></td>
                                <td>
                                    @if ($expense['receipt'])
                                        <strong>{{ $expense['receipt']['file_name'] }}</strong>
                                        <div class="text-muted small">{{ $expense['receipt']['scan_status'] }} / {{ $expense['receipt']['status'] }}</div>
                                    @else
                                        <div class="{{ $expense['requires_receipt'] ? 'text-warning' : 'text-muted' }} small">{{ $expense['requires_receipt'] ? 'Receipt required' : 'Receipt optional' }}</div>
                                        <div class="text-muted small">Upload via Documents (not yet available in this UI)</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($expense['status'] === 'DRAFT')
                                        @if ($canManageExpenses)
                                            <form method="POST" action="{{ route('operations.submit', $expense['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">Submit</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Not yet submitted</span>
                                        @endif
                                    @elseif ($expense['status'] === 'SUBMITTED')
                                        @if (! $canDecideExpenses)
                                            <span class="text-muted">Awaiting authorised reviewer</span>
                                        @elseif ($expense['created_by'] === $actorId)
                                            <span class="text-muted">Independent reviewer required</span>
                                        @else
                                            <div class="d-flex gap-1">
                                                <form method="POST" action="{{ route('operations.approve', $expense['id']) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('operations.reject', $expense['id']) }}" onsubmit="return operationsDecisionPrompt(this, 'rejection');">
                                                    @csrf
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                                </form>
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">Decision recorded</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No expenses on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Record an expense</div>
                <div class="text-muted small">Recorded as a draft; submit it for independent review once ready</div>
            </div>
            <div class="card-body">
                @if ($categories->isEmpty())
                    <p class="text-muted mb-0">No active expense categories are configured yet.</p>
                @else
                    <form method="POST" action="{{ route('operations.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="expense_number" class="form-label">Expense number</label>
                            <input type="text" class="form-control font-monospace" id="expense_number" name="expense_number" required maxlength="40" placeholder="EXP-2026-0001" value="{{ old('expense_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="category_id" class="form-label">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="" disabled selected>Select category</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected(old('category_id') === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="supplier_party_id" class="form-label">Supplier (optional)</label>
                            <select class="form-select" id="supplier_party_id" name="supplier_party_id">
                                <option value="">No supplier</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier['id'] }}" @selected(old('supplier_party_id') === $supplier['id'])>{{ $supplier['display_name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="expense_date" class="form-label">Expense date</label>
                            <input type="date" class="form-control" id="expense_date" name="expense_date" required value="{{ old('expense_date') }}">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" required maxlength="500" value="{{ old('description') }}">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="net_cents" class="form-label">Net (cents)</label>
                                <input type="number" class="form-control" id="net_cents" name="net_cents" required min="0" step="1" value="{{ old('net_cents') }}">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="tax_cents" class="form-label">VAT (cents)</label>
                                <input type="number" class="form-control" id="tax_cents" name="tax_cents" required min="0" step="1" value="{{ old('tax_cents') }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Record expense</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Inventory balances</div>
                <div class="text-muted small">Signed stock movements update versioned balances</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Inventory balances by warehouse and product</caption>
                    <thead>
                        <tr>
                            <th scope="col">Warehouse</th>
                            <th scope="col">Product</th>
                            <th scope="col">SKU</th>
                            <th scope="col">On hand</th>
                            <th scope="col">Average cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($balances as $balance)
                            <tr>
                                <td>{{ optional($balance->warehouse)->name }}</td>
                                <td><strong>{{ optional($balance->product)->name }}</strong></td>
                                <td class="font-monospace">{{ optional($balance->product)->sku }}</td>
                                <td>{{ $quantity($balance->quantity_micros) }}</td>
                                <td>NAD {{ number_format($balance->average_cost_cents / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No inventory balances on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Project control</div>
                <div class="text-muted small">Approved budget and actual costs by project</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Projects, their customer, period, budget, cost and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Project</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Period</th>
                            <th scope="col">Budget</th>
                            <th scope="col">Cost</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td>
                                    <strong>{{ $project['code'] }}</strong>
                                    <div class="text-muted small">{{ $project['name'] }}</div>
                                </td>
                                <td>{{ $project['customer_name'] ?? 'Internal' }}</td>
                                <td>
                                    {{ $project['start_date'] }}
                                    <div class="text-muted small">{{ $project['end_date'] ?? 'Open' }}</div>
                                </td>
                                <td>{{ $project['currency'] }} {{ number_format($project['budget_cents'] / 100, 2) }}</td>
                                <td>{{ $project['currency'] }} {{ number_format($project['cost_cents'] / 100, 2) }}</td>
                                <td><x-status-badge :value="$project['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No projects on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3" role="status">
    <strong>Import VAT evidence is not yet available in this UI.</strong><br>
    Customs declaration records have no backing module in this migration yet (only their database table exists) -- tracked separately in the migration matrix, not silently included here.
</div>

<script>
    function operationsDecisionPrompt(form, verb) {
        var reasonInput = form.querySelector('input[name=reason]');
        if (!reasonInput) return true;
        var reason = window.prompt('Record the independent ' + verb + ' reason.');
        if (!reason || !reason.trim()) return false;
        reasonInput.value = reason.trim();
        return true;
    }
</script>
@endsection

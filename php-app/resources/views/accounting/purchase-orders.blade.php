@extends('layouts.app')

@section('title', 'Purchase Orders')

@php
    $fmt = fn (int $cents) => 'N$ '.number_format($cents / 100, 2);
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Accounting & Finance</div>
    <h1 class="h3 mb-1">Purchase Orders</h1>
    <p class="text-muted mb-0">Issue an order to a registered supplier, have an independent reviewer approve it -- never the order's own creator -- then issue it and, once fulfilled, convert it into a real supplier expense.</p>
</div>

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
                <div class="fw-semibold">Purchase order register</div>
                <div class="text-muted small">DRAFT &rarr; SUBMITTED &rarr; APPROVED &rarr; ISSUED &rarr; CONVERTED, or REJECTED / CANCELLED</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Purchase orders, their supplier, amount, status and available action</caption>
                    <thead>
                        <tr>
                            <th scope="col">Order</th>
                            <th scope="col">Supplier / category</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td>
                                    <strong>{{ $order['po_number'] }}</strong>
                                    <div class="text-muted small">{{ $order['description'] }}</div>
                                    <div class="text-muted small">Issue {{ $order['issue_date'] }} &middot; valid until {{ $order['valid_until'] }}</div>
                                </td>
                                <td>
                                    {{ $order['supplier_name'] ?? '—' }}
                                    <div class="text-muted small">{{ $order['category_name'] }}</div>
                                </td>
                                <td>{{ $order['currency'] }} {{ number_format($order['total_cents'] / 100, 2) }}</td>
                                <td>
                                    <x-status-badge :value="$order['status']" type="status" />
                                    @if ($order['status'] === 'REJECTED' && $order['rejection_reason'])
                                        <div class="text-muted small">{{ $order['rejection_reason'] }}</div>
                                    @elseif ($order['status'] === 'CANCELLED' && $order['cancellation_reason'])
                                        <div class="text-muted small">{{ $order['cancellation_reason'] }}</div>
                                    @elseif ($order['status'] === 'CONVERTED' && $order['converted_expense_id'])
                                        <div class="text-muted small font-monospace">Expense {{ $order['converted_expense_id'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (! $canManage)
                                        <span class="text-muted">Read-only</span>
                                    @elseif ($order['status'] === 'DRAFT')
                                        <form method="POST" action="{{ route('accounting.purchase-orders.submit', $order['id']) }}">
                                            @csrf
                                            <x-idempotency-key />
                                            <button type="submit" class="btn btn-sm btn-primary">Submit</button>
                                        </form>
                                    @elseif ($order['status'] === 'SUBMITTED')
                                        @if ($order['created_by'] === $actorId)
                                            <span class="text-muted">Independent reviewer required</span>
                                        @else
                                            <div class="d-flex gap-1">
                                                <form method="POST" action="{{ route('accounting.purchase-orders.approve', $order['id']) }}">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('accounting.purchase-orders.reject', $order['id']) }}" onsubmit="return purchaseOrderDecisionPrompt(this, 'rejection');">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                                </form>
                                            </div>
                                        @endif
                                    @elseif ($order['status'] === 'APPROVED')
                                        <div class="d-flex gap-1">
                                            <form method="POST" action="{{ route('accounting.purchase-orders.issue', $order['id']) }}">
                                                @csrf
                                                <x-idempotency-key />
                                                <button type="submit" class="btn btn-sm btn-primary">Issue to supplier</button>
                                            </form>
                                            <form method="POST" action="{{ route('accounting.purchase-orders.cancel', $order['id']) }}" onsubmit="return purchaseOrderDecisionPrompt(this, 'cancellation');">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel</button>
                                            </form>
                                        </div>
                                    @elseif ($order['status'] === 'ISSUED')
                                        @if ($canConvert)
                                            <form method="POST" action="{{ route('accounting.purchase-orders.convert', $order['id']) }}" class="d-flex gap-1">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="text" name="expense_number" class="form-control form-control-sm" style="width: 10rem" placeholder="Expense number" required>
                                                <button type="submit" class="btn btn-sm btn-success text-nowrap">Convert to expense</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Awaiting fulfilment</span>
                                        @endif
                                    @else
                                        <span class="text-muted">Closed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No purchase orders on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Issue a purchase order</div>
                <div class="text-muted small">Requires an active registered supplier</div>
            </div>
            <div class="card-body">
                @if ($canManage)
                    <form method="POST" action="{{ route('accounting.purchase-orders.store') }}">
                        @csrf
                        <x-idempotency-key />
                        <div class="mb-2">
                            <label for="po_number" class="form-label small">Order number</label>
                            <input type="text" id="po_number" name="po_number" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-2">
                            <label for="supplier_party_id" class="form-label small">Supplier</label>
                            <select id="supplier_party_id" name="supplier_party_id" class="form-select form-select-sm" required>
                                <option value="">Select a supplier…</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label for="category_id" class="form-label small">Expense category</label>
                            <select id="category_id" name="category_id" class="form-select form-select-sm" required>
                                <option value="">Select a category…</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label for="description" class="form-label small">Description</label>
                            <input type="text" id="description" name="description" class="form-control form-control-sm" required>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col">
                                <label for="issue_date" class="form-label small">Issue date</label>
                                <input type="date" id="issue_date" name="issue_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col">
                                <label for="valid_until" class="form-label small">Valid until</label>
                                <input type="date" id="valid_until" name="valid_until" class="form-control form-control-sm" value="{{ now()->addDays(30)->toDateString() }}" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col">
                                <label for="net_cents" class="form-label small">Net (cents)</label>
                                <input type="number" id="net_cents" name="net_cents" class="form-control form-control-sm" min="0" required>
                            </div>
                            <div class="col">
                                <label for="tax_cents" class="form-label small">VAT (cents)</label>
                                <input type="number" id="tax_cents" name="tax_cents" class="form-control form-control-sm" min="0" value="0" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">Create purchase order</button>
                    </form>
                @else
                    <p class="text-muted small mb-0">You do not have permission to issue a purchase order.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    function purchaseOrderDecisionPrompt(form, verb) {
        var reasonInput = form.querySelector('input[name=reason]');
        if (!reasonInput) return true;
        var reason = window.prompt('Record the ' + verb + ' reason.');
        if (!reason || !reason.trim()) return false;
        reasonInput.value = reason.trim();
        return true;
    }
</script>
@endsection

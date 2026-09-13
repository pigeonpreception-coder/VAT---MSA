@extends('layouts.app')

@section('title', 'Logistics')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operations</div>
    <h1 class="h3 mb-1">Logistics Module</h1>
    <p class="text-muted mb-0">Delivery, dispatch and fleet-adjacent logistics tracking. A delivery always references the tax invoice (or POS sale) it fulfils.</p>
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
                <div class="fw-semibold">Delivery register</div>
                <div class="text-muted small">{{ count($deliveries) }} deliverie(s) on record</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Deliveries, their reference, route, status and available actions</caption>
                    <thead>
                        <tr>
                            <th scope="col">Delivery</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Route</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveries as $delivery)
                            <tr>
                                <td><strong>{{ $delivery['delivery_number'] }}</strong></td>
                                <td>
                                    {{ $delivery['reference_type'] }}
                                    @if ($delivery['reference_id'] ?? null)
                                        <div class="text-muted small font-monospace">{{ $delivery['reference_id'] }}</div>
                                    @endif
                                </td>
                                <td>{{ $delivery['origin'] }} &rarr; {{ $delivery['destination'] }}</td>
                                <td><x-status-badge :value="$delivery['status']" type="status" /></td>
                                @if ($canManage)
                                    <td>
                                        @if ($delivery['status'] === 'PENDING')
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('operations.logistics.dispatch', $delivery['id']) }}">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <button type="submit" class="btn btn-sm btn-primary">Dispatch</button>
                                                </form>
                                                <form method="POST" action="{{ route('operations.logistics.cancellation', $delivery['id']) }}" onsubmit="return logisticsReasonPrompt(this);">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            </div>
                                        @elseif ($delivery['status'] === 'IN_TRANSIT')
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('operations.logistics.delivery', $delivery['id']) }}">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <button type="submit" class="btn btn-sm btn-primary">Mark delivered</button>
                                                </form>
                                                <form method="POST" action="{{ route('operations.logistics.cancellation', $delivery['id']) }}" onsubmit="return logisticsReasonPrompt(this);">
                                                    @csrf
                                                    <x-idempotency-key />
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-muted">Closed</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">No deliveries are recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        @if ($canManage)
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold">Create a delivery</div>
                    <div class="text-muted small">New deliveries start Pending</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('operations.logistics.store') }}">
                        @csrf
                        <x-idempotency-key />
                        <div class="mb-3">
                            <label for="delivery_number" class="form-label">Delivery number</label>
                            <input type="text" class="form-control font-monospace" id="delivery_number" name="delivery_number" required maxlength="40" value="{{ old('delivery_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="reference_type" class="form-label">Reference type</label>
                            <select class="form-select" id="reference_type" name="reference_type" required>
                                <option value="INVOICE" @selected(old('reference_type', 'INVOICE') === 'INVOICE')>Invoice</option>
                                <option value="POS_SALE" @selected(old('reference_type') === 'POS_SALE')>POS sale</option>
                                <option value="OTHER" @selected(old('reference_type') === 'OTHER')>Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="reference_id" class="form-label">Reference (invoice ID, required unless Other)</label>
                            <input type="text" class="form-control font-monospace" id="reference_id" name="reference_id" maxlength="80" value="{{ old('reference_id') }}">
                        </div>
                        <div class="mb-3">
                            <label for="origin" class="form-label">Origin</label>
                            <input type="text" class="form-control" id="origin" name="origin" required maxlength="300" value="{{ old('origin') }}">
                        </div>
                        <div class="mb-3">
                            <label for="destination" class="form-label">Destination</label>
                            <input type="text" class="form-control" id="destination" name="destination" required maxlength="300" value="{{ old('destination') }}">
                        </div>
                        <div class="mb-3">
                            <label for="vehicle_asset_id" class="form-label">Vehicle (optional)</label>
                            <select class="form-select" id="vehicle_asset_id" name="vehicle_asset_id">
                                <option value="">No vehicle assigned</option>
                                @foreach ($vehicles as $vehicle)
                                    <option value="{{ $vehicle['id'] }}" @selected(old('vehicle_asset_id') === $vehicle['id'])>{{ $vehicle['asset_code'] }} &mdash; {{ $vehicle['description'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (optional)</label>
                            <input type="text" class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Create delivery</button>
                    </form>
                </div>
            </div>
        @else
            <div class="card h-100"><div class="card-body text-muted">You have read-only access to this register.</div></div>
        @endif
    </div>
</div>

<script>
    function logisticsReasonPrompt(form) {
        var reasonInput = form.querySelector('input[name=reason]');
        var reason = window.prompt('Record the cancellation reason.');
        if (!reason || !reason.trim()) return false;
        reasonInput.value = reason.trim();
        return true;
    }
</script>
@endsection

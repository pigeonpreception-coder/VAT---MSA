@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operations</div>
    <h1 class="h3 mb-1">{{ $title }}</h1>
    <p class="text-muted mb-0">Register, valuation and disposal tracking. Immovable and Movable Asset Management share one register, filtered here to {{ strtolower($assetClass) }} assets only.</p>
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
                <div class="fw-semibold">Asset register</div>
                <div class="text-muted small">{{ count($assets) }} {{ strtolower($assetClass) }} asset(s) on record</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Assets, their category, custodian, value, status and available actions</caption>
                    <thead>
                        <tr>
                            <th scope="col">Asset</th>
                            <th scope="col">Category</th>
                            <th scope="col">Location</th>
                            <th scope="col">Value</th>
                            <th scope="col">Status</th>
                            @if ($canManage)
                                <th scope="col">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($assets as $asset)
                            <tr>
                                <td>
                                    <strong>{{ $asset['asset_code'] }}</strong>
                                    <div class="text-muted small">{{ $asset['description'] }}</div>
                                    @if ($asset['serial_or_registration_number'] ?? null)
                                        <div class="text-muted small font-monospace">{{ $asset['serial_or_registration_number'] }}</div>
                                    @endif
                                </td>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $asset['category']))) }}</td>
                                <td>{{ $asset['location_or_address'] }}</td>
                                <td>NAD {{ number_format(($asset['current_value_cents'] ?? $asset['acquisition_cost_cents']) / 100, 2) }}</td>
                                <td><x-status-badge :value="$asset['status']" type="status" /></td>
                                @if ($canManage)
                                    <td>
                                        @if ($asset['status'] === 'ACTIVE')
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('operations.fixed-assets.maintenance', $asset['id']) }}">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="{{ $routeName }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Flag maintenance</button>
                                                </form>
                                                <form method="POST" action="{{ route('operations.fixed-assets.disposal', $asset['id']) }}" onsubmit="return fixedAssetReasonPrompt(this, 'disposal');">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="{{ $routeName }}">
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Dispose</button>
                                                </form>
                                            </div>
                                        @elseif ($asset['status'] === 'UNDER_MAINTENANCE')
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('operations.fixed-assets.restoration', $asset['id']) }}">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="{{ $routeName }}">
                                                    <button type="submit" class="btn btn-sm btn-primary">Restore</button>
                                                </form>
                                                <form method="POST" action="{{ route('operations.fixed-assets.disposal', $asset['id']) }}" onsubmit="return fixedAssetReasonPrompt(this, 'disposal');">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="{{ $routeName }}">
                                                    <input type="hidden" name="reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Dispose</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-muted">Disposed</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="text-center text-muted py-4">No {{ strtolower($assetClass) }} assets are registered yet.</td></tr>
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
                    <div class="fw-semibold">Register an asset</div>
                    <div class="text-muted small">New assets start Active</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('operations.fixed-assets.store') }}">
                        @csrf
                        <input type="hidden" name="asset_class" value="{{ $assetClass }}">
                        <input type="hidden" name="return_to" value="{{ $routeName }}">
                        <div class="mb-3">
                            <label for="asset_code" class="form-label">Asset code</label>
                            <input type="text" class="form-control font-monospace" id="asset_code" name="asset_code" required maxlength="40" value="{{ old('asset_code') }}">
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="" disabled selected>Select category</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" @selected(old('category') === $category)>{{ ucwords(strtolower(str_replace('_', ' ', $category))) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" required maxlength="300" value="{{ old('description') }}">
                        </div>
                        <div class="mb-3">
                            <label for="serial_or_registration_number" class="form-label">{{ $serialLabel }} (optional)</label>
                            <input type="text" class="form-control" id="serial_or_registration_number" name="serial_or_registration_number" maxlength="80" value="{{ old('serial_or_registration_number') }}">
                        </div>
                        <div class="mb-3">
                            <label for="location_or_address" class="form-label">Location / address</label>
                            <input type="text" class="form-control" id="location_or_address" name="location_or_address" required maxlength="300" value="{{ old('location_or_address') }}">
                        </div>
                        <div class="mb-3">
                            <label for="acquisition_date" class="form-label">Acquisition date</label>
                            <input type="date" class="form-control" id="acquisition_date" name="acquisition_date" required value="{{ old('acquisition_date') }}">
                        </div>
                        <div class="mb-3">
                            <label for="acquisition_cost_cents" class="form-label">Acquisition cost (cents)</label>
                            <input type="number" class="form-control" id="acquisition_cost_cents" name="acquisition_cost_cents" required min="0" step="1" value="{{ old('acquisition_cost_cents') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">Register asset</button>
                    </form>
                </div>
            </div>
        @else
            <div class="card h-100"><div class="card-body text-muted">You have read-only access to this register.</div></div>
        @endif
    </div>
</div>

<script>
    function fixedAssetReasonPrompt(form, verb) {
        var reasonInput = form.querySelector('input[name=reason]');
        if (!reasonInput) return true;
        var reason = window.prompt('Record the ' + verb + ' reason.');
        if (!reason || !reason.trim()) return false;
        reasonInput.value = reason.trim();
        return true;
    }
</script>
@endsection

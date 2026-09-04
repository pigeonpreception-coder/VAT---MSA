@extends('layouts.app')

@section('title', 'Licensing')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Platform domain</div>
        <h1 class="h3 mb-1">Licensing &amp; Entitlements</h1>
        <p class="text-muted mb-0">{{ $organisation['legal_name'] }}'s current licence, feature entitlements, and metered usage.</p>
    </div>
    <x-status-badge :value="$license['state']" type="license" />
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Licence</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Plan</span><span>{{ $license['plan_name'] }} (v{{ $license['plan_version'] }})</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Retention</span><span>{{ ucwords(strtolower(str_replace('_', ' ', $license['retention_policy']))) }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Period</span><span>{{ \Illuminate\Support\Carbon::parse($license['current_period_start'])->format('d M Y') }} &ndash; {{ \Illuminate\Support\Carbon::parse($license['current_period_end'])->format('d M Y') }}</span></li>
            </ul>
        </div>

        @can('permission', 'licensing:manage')
            <div class="card">
                <div class="card-header">Change licence state</div>
                <div class="card-body">
                    @if (empty($availableActions))
                        <p class="text-muted small mb-0">No state change is valid from {{ ucfirst(strtolower($license['state'])) }}.</p>
                    @else
                        {{-- Step-up gated: routes/web.php applies 'password.confirm'
                             to this route, matching the JSON API's own
                             /licensing/state POST. --}}
                        <form method="POST" action="{{ route('licensing.state.store') }}">
                            @csrf
                            <div class="mb-2">
                                <label for="action" class="form-label small mb-0">Action</label>
                                <select id="action" name="action" class="form-select form-select-sm @error('action') is-invalid @enderror" required>
                                    @foreach ($availableActions as $action)
                                        <option value="{{ $action }}" @selected(old('action') === $action)>{{ ucfirst(strtolower($action)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="reason" class="form-label small mb-0">Reason</label>
                                <textarea id="reason" name="reason" minlength="5" maxlength="240" rows="2" required class="form-control form-control-sm @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">Apply</button>
                        </form>
                    @endif
                </div>
            </div>
        @endcan
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Entitlements</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Feature entitlements for this licence plan</caption>
                    <thead>
                        <tr>
                            <th scope="col">Feature</th>
                            <th scope="col">Enabled</th>
                            <th scope="col" class="text-end">Usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entitlements as $entitlement)
                            <tr>
                                <td>{{ $entitlement['name'] }}<div class="text-muted small">{{ $entitlement['description'] }}</div></td>
                                <td>
                                    @if ($entitlement['enabled'])
                                        <span class="badge text-bg-success">Enabled</span>
                                    @else
                                        <span class="badge text-bg-secondary">Disabled</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($entitlement['limit_value'] !== null)
                                        {{ $entitlement['used_value'] }} / {{ $entitlement['limit_value'] }}
                                    @elseif ($entitlement['metric_key'])
                                        {{ $entitlement['used_value'] }} (unlimited)
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Usage by period</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Metered usage by billing period</caption>
                    <thead>
                        <tr>
                            <th scope="col">Metric</th>
                            <th scope="col">Period</th>
                            <th scope="col" class="text-end">Used</th>
                            <th scope="col" class="text-end">Reserved</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($usage as $row)
                            <tr>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $row['metric_key']))) }}</td>
                                <td>{{ $row['period_key'] }}</td>
                                <td class="text-end">{{ $row['used_value'] }}</td>
                                <td class="text-end">{{ $row['reserved_value'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No metered usage recorded for this licence.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

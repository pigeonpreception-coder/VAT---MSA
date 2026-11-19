@extends('layouts.app')

@section('title', $tenantAuthorityShortName.' administration portal')

@php
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $date = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $federationReady = collect($governance['federation'])->where('status', 'PRODUCTION_APPROVED')->count();
    $productionActivated = collect($governance['onboardingCases'])->where('status', 'PRODUCTION_ACTIVATED')->count();
    $unitNames = collect($governance['units'])->pluck('name', 'id');
    // Assignment scope is stored as JSON, e.g. {"jurisdiction":"NA","environment":"LOCAL_STAGING"}.
    $scope = function (?string $json) {
        $parts = json_decode((string) $json, true);
        if (! is_array($parts)) {
            return $json ?: '—';
        }

        $show = fn ($value) => match (true) {
            ! is_scalar($value) => json_encode($value),
            strlen((string) $value) <= 3 => (string) $value,
            default => \App\Support\Ui\Humanize::enum((string) $value),
        };

        return collect($parts)->map(fn ($value, $key) => \App\Support\Ui\Humanize::enum($key).': '.$show($value))->implode(' · ');
    };
@endphp

@section('content')
<x-page-header :title="$tenantAuthorityShortName.' administration portal'" :back="route('portals.index')" back-label="Portals"
    subtitle="Authority structure, identity federation, protected administrator roles and quarterly access reviews." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Authorities" icon="bank2" :value="number_format(count($governance['authorities']))" hint="Tax authorities you administer" />
    </div>
    <div class="col">
        <x-stat-tile label="Authority units" icon="diagram-3" :value="number_format(count($governance['units']))" hint="Units in the authority hierarchy" />
    </div>
    <div class="col">
        <x-stat-tile label="Federation ready" icon="person-badge" :value="number_format($federationReady)" hint="Needs a signed contract and conformance evidence" tone="warning" />
    </div>
    <div class="col">
        <x-stat-tile label="Production activations" icon="rocket-takeoff" :value="number_format($productionActivated)" hint="Not enabled in this environment" tone="warning" />
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Authority hierarchy</div>
                <div class="text-muted small">Organisational units and the jurisdiction they cover</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Authority units, their type and status</caption>
                    <thead><tr><th scope="col">Unit</th><th scope="col">Type</th><th scope="col">Parent unit</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['units'] as $item)
                            <tr>
                                <td><span class="fw-semibold">{{ $item['name'] }}</span><div class="text-muted small">{{ $item['code'] }}</div></td>
                                <td>@humanize($item['unit_type'])</td>
                                <td>{{ $item['parent_unit_id'] ? ($unitNames[$item['parent_unit_id']] ?? '—') : '—' }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">No authority units on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Identity federation</div>
                <div class="text-muted small">Connections stay unconfirmed until the authority contract is approved</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Identity federation connections, their environment, protocol and status</caption>
                    <thead><tr><th scope="col">Provider</th><th scope="col">Environment</th><th scope="col">Protocol</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['federation'] as $item)
                            <tr>
                                <td><span class="fw-semibold">{{ $item['display_name'] }}</span><div class="text-muted small">{{ $item['provider_key'] }}</div></td>
                                <td>@humanize($item['environment'])</td>
                                <td>@humanize($item['protocol'])</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">No federation connections on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Protected administrator assignments</div>
        <div class="text-muted small">Preparing and activating changes are always held by different people</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Administrators with a protected governance role assignment</caption>
            <thead><tr><th scope="col">Administrator</th><th scope="col">Role</th><th scope="col">Duty</th><th scope="col">Scope</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($governance['assignments'] as $item)
                    <tr>
                        <td><span class="fw-semibold">{{ $item['display_name'] }}</span><div class="text-muted small">{{ $item['email'] }}</div></td>
                        <td>{{ $item['role_name'] }}</td>
                        <td>@humanize($item['duty_class'])</td>
                        <td class="small">{{ $scope($item['scope']) }}</td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No protected assignments on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Quarterly access reviews</div>
                <div class="text-muted small">Privileged changes are blocked unless a current review exists</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Quarterly access reviews, their period, due date and status</caption>
                    <thead><tr><th scope="col">Period starting</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($governance['accessReviews'] as $item)
                            <tr>
                                <td>{{ $date($item['period_start']) }}</td>
                                <td>{{ $dateTime($item['due_at']) }}</td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">No access reviews on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Identity providers</div>
                <div class="text-muted small">Signing in through a provider never grants access on its own</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <caption class="visually-hidden">Identity providers, their status and configuration</caption>
                    <thead><tr><th scope="col">Provider</th><th scope="col">Status</th><th scope="col">Configuration</th></tr></thead>
                    <tbody>
                        @forelse ($identity['providers'] as $item)
                            <tr>
                                <td><span class="fw-semibold">{{ $item['display_name'] }}</span><div class="text-muted small">{{ $item['provider_key'] }}</div></td>
                                <td><x-status-badge :value="$item['status']" type="status" /></td>
                                <td><x-status-badge :value="$item['configuration_status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">No identity providers on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Approvals recorded here exercise the governance workflow only. They don't switch on ITAS federation, a production tax authority, statutory rules or taxpayer access.
</p>
@endsection

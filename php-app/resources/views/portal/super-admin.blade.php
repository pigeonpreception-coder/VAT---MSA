@extends('layouts.app')

@section('title', 'Super administration portal')

@php
    $disabledIntegrations = collect($snapshot['integrations'])->where('operational_status', 'DISABLED')->count();
    $pendingEvents = (int) (collect($snapshot['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0);
    $criticalSecurity = (int) (collect($snapshot['securityEvents'])->firstWhere('severity', 'CRITICAL')['count'] ?? 0);
@endphp

@section('content')
<x-page-header title="Super administration portal" :back="route('portals.index')" back-label="Portals"
    subtitle="Technical health, integrations and security for the platform. Taxpayer and return data are not shown here." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Service components" icon="hdd-stack" :value="number_format(count($snapshot['components']))" hint="Monitored platform services" />
    </div>
    <div class="col">
        <x-stat-tile label="Disabled integrations" icon="plug" :value="number_format($disabledIntegrations)" hint="Waiting on contracts or credentials" :tone="$disabledIntegrations > 0 ? 'warning' : 'success'" />
    </div>
    <div class="col">
        <x-stat-tile label="Queued events" icon="hourglass-split" :value="number_format($pendingEvents)" hint="Events waiting to be delivered" />
    </div>
    <div class="col">
        <x-stat-tile label="Critical security events" icon="shield-exclamation" :value="number_format($criticalSecurity)" :hint="$criticalSecurity > 0 ? 'Needs investigation' : 'None recorded'" :tone="$criticalSecurity > 0 ? 'danger' : 'success'" />
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Service components</div>
        <div class="text-muted small">Configuration and operational status of each platform service</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Service components, their criticality, configuration and operational status</caption>
            <thead>
                <tr><th scope="col">Component</th><th scope="col">Type</th><th scope="col">Criticality</th><th scope="col">Configuration</th><th scope="col">Operations</th><th scope="col">Dependencies</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['components'] as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item['display_name'] }}</td>
                        <td>@humanize($item['component_type'])</td>
                        <td><x-status-badge :value="$item['criticality']" type="risk" /></td>
                        <td><x-status-badge :value="$item['configuration_status']" type="status" /></td>
                        <td><x-status-badge :value="$item['operational_status']" type="status" /></td>
                        <td class="text-muted small">{{ $item['dependency_summary'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No service components on record.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

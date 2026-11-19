@extends('layouts.app')

@section('title', $usage['provider']['legal_name'])

@section('content')
@php
    $application = $usage['applications'][0] ?? null;
    $approvalFor = fn (string $environment) => collect($usage['environmentApprovals'])->firstWhere('environment', $environment);
@endphp

<x-page-header :title="$usage['provider']['legal_name']" :back="route('saas-providers.index')" back-label="SaaS providers"
    :subtitle="$usage['provider']['contact_email']">
    <x-slot:actions><x-status-badge :value="$usage['provider']['status']" type="status" /></x-slot:actions>
</x-page-header>

<x-form-errors />

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Application</div>
            <div class="card-body">
                @if ($application)
                    <h2 class="h6">{{ $application['name'] }}</h2>
                    <p class="text-muted small">{{ $application['description'] }}</p>
                                        <dl class="row small mb-0">
                        <dt class="col-5">Provider key</dt><dd class="col-7 font-monospace">{{ $usage['provider']['provider_key'] }}</dd>
                        <dt class="col-5">Endpoint</dt><dd class="col-7">{{ $application['endpoint_reference'] }}</dd>
                        <dt class="col-5">Capabilities</dt><dd class="col-7">{{ implode(', ', $application['requested_capabilities']) }}</dd>
                        <dt class="col-5">Status</dt><dd class="col-7"><x-status-badge :value="$application['status']" type="status" /></dd>
                    </dl>
                @else
                    <p class="text-muted mb-0">No application on record.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Environment approvals</div>
            <div class="card-body">
                @foreach (['SANDBOX', 'PRODUCTION'] as $environment)
                    @php($approval = $approvalFor($environment))
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>@humanize($environment)</span>
                        @if ($approval)
                            <x-status-badge :value="$approval['status']" type="status" />
                        @else
                            <span class="text-muted small">No conformance run yet</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@can('permission', 'developer:manage')
    @if ($application)
        <div class="card my-3">
            <div class="card-body">
                <h2 class="h6">Submit a conformance run</h2>
                <p class="text-muted small mb-2">Runs {{ $tenantAuthorityShortName }}'s standard conformance tests for the capabilities and events below. A passed sandbox run is approved straight away; a passed production run needs {{ $tenantAuthorityShortName }}'s approval.</p>
                <form method="POST" action="{{ route('saas-applications.conformance-runs.store', $application['id']) }}" class="row g-2">
                    @csrf
                    <x-idempotency-key />
                    <input type="hidden" name="provider_id" value="{{ $usage['provider']['id'] }}">
                    <div class="col-md-3">
                        <label for="environment" class="form-label small mb-0">Environment</label>
                        <select id="environment" name="environment" class="form-select form-select-sm" required>
                            <option value="SANDBOX">Sandbox</option>
                            <option value="PRODUCTION">Production</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="tested_capabilities" class="form-label small mb-0">Tested capabilities (comma-separated)</label>
                        <input type="text" id="tested_capabilities" name="tested_capabilities" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label for="acknowledged_events" class="form-label small mb-0">Acknowledged events (comma-separated)</label>
                        <input type="text" id="acknowledged_events" name="acknowledged_events" placeholder="InvoiceCreated, InvoiceCertified" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endcan

<div class="card mb-3">
    <div class="card-header">
        <span class="text-muted small">Real usage &middot; {{ $usage['connectionCount'] }} integration connection{{ $usage['connectionCount'] === 1 ? '' : 's' }}</span>
    </div>
    <div class="card-body">
        <p class="small mb-2">Sync jobs across those connections: <strong>{{ $usage['syncStats']['totalJobs'] }}</strong> total, <strong>{{ $usage['syncStats']['failedJobs'] }}</strong> failed.</p>
        @if ($usage['connectionCount'] === 0)
            <p class="text-muted small mb-0">No organisation has connected an integration to this provider yet.</p>
        @endif
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'ERP overview')

@section('content')
<x-page-header title="ERP overview"
    subtitle="Human resources, assets, inventory, logistics and projects at a glance. Each tile opens the module that holds the data." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-3">
    <div class="col">
        <a href="{{ $canReadEmployees ? route('operations.human-resources') : '#' }}" class="text-decoration-none {{ $canReadEmployees ? '' : 'opacity-50' }}">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="stat-label">Human Resources</div>
                <div class="stat-value text-body">{{ number_format($activeEmployees) }}</div>
                <div class="small text-muted">{{ $canReadEmployees ? 'Active employees' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ $canReadAssets ? route('operations.immovable-assets') : '#' }}" class="text-decoration-none {{ $canReadAssets ? '' : 'opacity-50' }}">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="stat-label">Fixed Assets</div>
                <div class="stat-value text-body">{{ $tenantCurrencyCode }} {{ number_format($assetValueCents / 100, 2) }}</div>
                <div class="small text-muted">{{ $canReadAssets ? $assetCount.' in service' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('operations.inventory') }}" class="text-decoration-none">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="stat-label">Inventory (POS)</div>
                <div class="stat-value text-body">{{ $tenantCurrencyCode }} {{ number_format($inventoryValueCents / 100, 2) }}</div>
                <div class="small text-muted">{{ $productCount }} products</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ $canReadLogistics ? route('operations.logistics') : '#' }}" class="text-decoration-none {{ $canReadLogistics ? '' : 'opacity-50' }}">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="stat-label">Logistics</div>
                <div class="stat-value text-body">{{ number_format($inTransitDeliveries) }}</div>
                <div class="small text-muted">{{ $canReadLogistics ? $pendingDeliveries.' pending dispatch' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('project-management.ongoing') }}" class="text-decoration-none">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="stat-label">Projects</div>
                <div class="stat-value text-body">{{ number_format($activeProjects) }}</div>
                <div class="small text-muted">Planned or active</div>
            </div></div>
        </a>
    </div>
</div>

<p class="small text-muted mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    This page summarises Human resources, Immovable and movable assets, Inventory and Logistics. Open each module to work with its records.
</p>
@endsection

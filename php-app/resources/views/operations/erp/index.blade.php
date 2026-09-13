@extends('layouts.app')

@section('title', 'ERP overview')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operations</div>
    <h1 class="h3 mb-1">ERP Module</h1>
    <p class="text-muted mb-0">A cross-module resource overview &mdash; Human Resources, Assets, Inventory, Logistics and Projects in one view. Each tile links to the module that owns the underlying data; this page holds no data of its own.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-3">
    <div class="col">
        <a href="{{ $canReadEmployees ? route('operations.human-resources') : '#' }}" class="text-decoration-none {{ $canReadEmployees ? '' : 'opacity-50' }}">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Human Resources</div>
                <div class="fs-2 fw-semibold text-body">{{ number_format($activeEmployees) }}</div>
                <div class="small text-muted">{{ $canReadEmployees ? 'Active employees' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ $canReadAssets ? route('operations.immovable-assets') : '#' }}" class="text-decoration-none {{ $canReadAssets ? '' : 'opacity-50' }}">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Fixed Assets</div>
                <div class="fs-2 fw-semibold text-body">NAD {{ number_format($assetValueCents / 100, 2) }}</div>
                <div class="small text-muted">{{ $canReadAssets ? $assetCount.' in service' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('operations.inventory') }}" class="text-decoration-none">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Inventory (POS)</div>
                <div class="fs-2 fw-semibold text-body">NAD {{ number_format($inventoryValueCents / 100, 2) }}</div>
                <div class="small text-muted">{{ $productCount }} products</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ $canReadLogistics ? route('operations.logistics') : '#' }}" class="text-decoration-none {{ $canReadLogistics ? '' : 'opacity-50' }}">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Logistics</div>
                <div class="fs-2 fw-semibold text-body">{{ number_format($inTransitDeliveries) }}</div>
                <div class="small text-muted">{{ $canReadLogistics ? $pendingDeliveries.' pending dispatch' : 'No permission' }}</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('project-management.ongoing') }}" class="text-decoration-none">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Projects</div>
                <div class="fs-2 fw-semibold text-body">{{ number_format($activeProjects) }}</div>
                <div class="small text-muted">Planned or active</div>
            </div></div>
        </a>
    </div>
</div>

<div class="alert alert-info">
    <strong>This overview aggregates existing modules.</strong><br>
    Human Resources, Immovable/Movable Asset Management, Inventory (point of sale) and Logistics are real, working modules &mdash; this page holds no data of its own, it only summarises theirs.
</div>
@endsection

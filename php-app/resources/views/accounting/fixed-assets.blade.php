@extends('layouts.app')

@section('title', 'Fixed Asset Module')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Accounting & Finance</div>
    <h1 class="h3 mb-1">Fixed Asset Module</h1>
    <p class="text-muted mb-0">Asset register, valuation and disposal tracking now live under Operations, split by asset class rather than duplicated here.</p>
</div>

<div class="row row-cols-1 row-cols-md-2 g-3">
    <div class="col">
        <a href="{{ route('operations.immovable-assets') }}" class="text-decoration-none">
            <div class="card h-100"><div class="card-body">
                <div class="fw-semibold">Immovable Asset Management</div>
                <div class="text-muted small">Land and buildings register, valuation and disposal tracking.</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('operations.movable-assets') }}" class="text-decoration-none">
            <div class="card h-100"><div class="card-body">
                <div class="fw-semibold">Movable Asset Management</div>
                <div class="text-muted small">Vehicles, equipment and other movable assets register and tracking.</div>
            </div></div>
        </a>
    </div>
</div>
@endsection

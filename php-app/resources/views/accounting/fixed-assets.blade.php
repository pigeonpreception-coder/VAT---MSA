@extends('layouts.app')

@section('title', 'Fixed assets')

@section('content')
<x-page-header title="Fixed assets"
    subtitle="The fixed asset register lives under Operations, split into immovable and movable assets." />

<div class="row row-cols-1 row-cols-md-2 g-3">
    <div class="col">
        <a href="{{ route('operations.immovable-assets') }}" class="text-decoration-none">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="fw-semibold">Immovable Asset Management</div>
                <div class="text-muted small">Land and buildings register, valuation and disposal tracking.</div>
            </div></div>
        </a>
    </div>
    <div class="col">
        <a href="{{ route('operations.movable-assets') }}" class="text-decoration-none">
            <div class="card h-100 stat-tile"><div class="card-body">
                <div class="fw-semibold">Movable Asset Management</div>
                <div class="text-muted small">Vehicles, equipment and other movable assets register and tracking.</div>
            </div></div>
        </a>
    </div>
</div>
@endsection

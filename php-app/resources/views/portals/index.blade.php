@extends('layouts.app')

@section('title', 'Portals')

@php
    $portalIcons = [
        'buyer' => 'cart3', 'seller' => 'shop', 'namra' => 'bank2', 'namra-admin' => 'building-gear',
        'super-admin' => 'shield-lock', 'developer' => 'code-slash',
    ];
@endphp

@section('content')
<x-page-header title="Portals" subtitle="Each portal is a focused workspace for one kind of work. You see the portals your role and organisation can use." />

@if (count($portals))
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
        @foreach ($portals as $portal)
            <div class="col">
                {{-- Each portal's 'href' (PortalDefinitions::all()) is its real route path. --}}
                <a href="{{ $portal['href'] }}" class="card h-100 portal-card text-decoration-none">
                    <div class="card-body">
                        <span class="icon-circle mb-3"><i class="bi bi-{{ $portalIcons[$portal['key']] ?? 'grid' }}" aria-hidden="true"></i></span>
                        <div class="stat-label mb-1">{{ $portal['audience'] }}</div>
                        <h2 class="h5 mb-2">{{ $portal['name'] }}</h2>
                        <p class="text-muted mb-3">{{ $portal['description'] }}</p>
                        <span class="portal-card-cta">Open {{ $portal['name'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@else
    <div class="card">
        <div class="empty-state">
            <i class="bi bi-grid" aria-hidden="true"></i>
            <strong>No portals available</strong>
            Your account isn't assigned to a portal yet. Ask your access administrator to assign a role.
        </div>
    </div>
@endif
@endsection

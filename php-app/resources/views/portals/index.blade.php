@extends('layouts.app')

@section('title', 'Portal switchboard')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Workspace switchboard</div>
    <h1 class="h3 mb-1">Choose an authorised VAT-MSA experience</h1>
    <p class="text-muted mb-0">Portal availability derives from identity role, active organisation scope and Buyer or Seller capability. Switching workspace changes tasks and visibility, not the canonical taxpayer record.</p>
</div>

@if (count($portals))
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        @foreach ($portals as $portal)
            <div class="col">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="text-uppercase text-muted small fw-semibold mb-1">{{ $portal['audience'] }}</div>
                        <h2 class="h5">{{ $portal['name'] }}</h2>
                        <p class="text-muted flex-grow-1">{{ $portal['description'] }}</p>
                        {{-- Only 'buyer' has a real destination built so far
                             (route('portal.buyer')) -- the other five fall
                             back to the one real authenticated landing page
                             this port has, matching PortalViewController's
                             own doc comment, until their own dashboards
                             exist. --}}
                        <a href="{{ $portal['key'] === 'buyer' ? route('portal.buyer') : route('dashboard') }}" class="btn btn-primary align-self-start">Open {{ $portal['name'] }}</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="card">
        <div class="card-body">
            <strong>No portal assignment.</strong>
            Your identity is authenticated but has no active portal role or organisation capability. Contact an authorised access administrator.
        </div>
    </div>
@endif

<div class="alert alert-info mt-3" role="status">
    <strong>Separation is enforced server-side.</strong><br>
    A hidden navigation link does not grant access. Each portal route re-evaluates role and capability, and every domain repository still applies record scope.
</div>
@endsection

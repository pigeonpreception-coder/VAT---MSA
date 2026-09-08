@extends('layouts.app')

@section('title', 'Organisations')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Identity domain</div>
    <h1 class="h3 mb-1">Organisations</h1>
    <p class="text-muted mb-0">Module 1's own identity foundation -- taxpayer organisations, identity providers, and platform-wide access counts.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header">Identity providers</div>
            <ul class="list-group list-group-flush">
                @foreach ($snapshot['providers'] as $provider)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $provider['display_name'] }}</strong>
                            <div class="text-muted small">{{ ucwords(strtolower(str_replace('_', ' ', $provider['provider_type']))) }} &middot; {{ ucwords(strtolower(str_replace('_', ' ', $provider['configuration_status']))) }}</div>
                        </div>
                        <x-status-badge :value="$provider['status']" type="status" />
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">Access counts</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Active users</span><strong>{{ $snapshot['access']['active_users'] }}</strong></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Identity links</span><strong>{{ $snapshot['access']['active_identity_links'] }}</strong></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Memberships</span><strong>{{ $snapshot['access']['active_memberships'] }}</strong></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Branches</span><strong>{{ $snapshot['access']['active_branches'] }}</strong></li>
            </ul>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">Organisations</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Organisations, with their branch and member counts</caption>
            <thead>
                <tr>
                    <th scope="col">Organisation</th>
                    <th scope="col">Taxpayer</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Branches</th>
                    <th scope="col" class="text-end">Members</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organisations as $organisation)
                    <tr>
                        <td><a href="{{ route('organisations.show', $organisation->id) }}"><strong>{{ $organisation->legal_name }}</strong></a></td>
                        <td>{{ $organisation->taxpayer?->legal_name }} <span class="text-muted small">{{ $organisation->taxpayer?->vat_number }}</span></td>
                        <td><x-status-badge :value="$organisation->status" type="status" /></td>
                        <td class="text-end">{{ $organisation->branch_count }}</td>
                        <td class="text-end">{{ $organisation->member_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No organisations are visible in this scope.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if (! empty($snapshot['registrations']))
    <div class="card">
        <div class="card-header">Recent registration applications</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <caption class="visually-hidden">Recent registration applications</caption>
                <thead>
                    <tr>
                        <th scope="col">Applicant</th>
                        <th scope="col">VAT number</th>
                        <th scope="col">Status</th>
                        <th scope="col">Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($snapshot['registrations'], 0, 10) as $registration)
                        <tr>
                            <td>{{ $registration['legal_name'] }}</td>
                            <td class="font-monospace">{{ $registration['vat_number'] ?? '—' }}</td>
                            <td><x-status-badge :value="$registration['status']" type="status" /></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($registration['submitted_at'])->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

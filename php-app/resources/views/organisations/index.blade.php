@extends('layouts.app')

@section('title', 'Organisations')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Identity domain</div>
        <h1 class="h3 mb-1">Organisations</h1>
        <p class="text-muted mb-0">Module 1's own identity foundation -- taxpayer organisations, identity providers, and platform-wide access counts.</p>
    </div>
    <a href="{{ route('registrations.index') }}" class="btn btn-primary text-nowrap">New registration</a>
</div>

@php
    $pendingRegistrations = collect($snapshot['registrations'])->whereNotIn('status', ['APPROVED', 'REJECTED', 'CANCELLED'])->count();
    $sidePreviewLimit = 5;
    $recentRegistrations = array_slice($snapshot['registrations'], 0, 10);
@endphp

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Canonical organisations</span><span>O</span></div>
                <div class="fs-2 fw-semibold">{{ number_format(count($organisations)) }}</div>
                <div class="small text-success">One-to-one taxpayer mappings</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Active branches</span><span>B</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($snapshot['access']['active_branches']) }}</div>
                <div class="small text-muted">Branch-scoped access boundary</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Linked identities</span><span>ID</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($snapshot['access']['active_identity_links']) }}</div>
                <div class="small text-muted">Provider subject links -- not email identity</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Pending registrations</span><span>!</span></div>
                <div class="fs-2 fw-semibold">{{ number_format($pendingRegistrations) }}</div>
                <div class="small text-warning">No auto-activation before authority checks</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Organisations</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Organisations, with their branch and member counts</caption>
                    <thead>
                        <tr>
                            <th scope="col">Organisation</th>
                            <th scope="col">Taxpayer</th>
                            <th scope="col">Capabilities</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Branches &amp; members</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($organisations as $organisation)
                            <tr>
                                <td><a href="{{ route('organisations.show', $organisation->id) }}"><strong>{{ $organisation->legal_name }}</strong></a></td>
                                <td>{{ $organisation->taxpayer?->legal_name }} <span class="text-muted small">{{ $organisation->taxpayer?->vat_number }}</span></td>
                                <td>
                                    @foreach (array_filter(explode(',', $organisation->capabilities_summary ?? '')) as $capability)
                                        <x-status-badge :value="$capability" type="status" />
                                    @endforeach
                                </td>
                                <td><x-status-badge :value="$organisation->status" type="status" /></td>
                                <td class="text-end">
                                    {{ number_format($organisation->branch_count) }} branches
                                    <div class="text-muted small">{{ number_format($organisation->member_count) }} members</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No organisations are visible in this scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header fw-semibold">Access counts</div>
            <div class="card-body">
                <div class="mb-2 d-flex justify-content-between"><span class="text-muted">Active users</span><span class="fw-semibold">{{ number_format($snapshot['access']['active_users']) }}</span></div>
                <div class="mb-2 d-flex justify-content-between"><span class="text-muted">Identity links</span><span class="fw-semibold">{{ number_format($snapshot['access']['active_identity_links']) }}</span></div>
                <div class="mb-2 d-flex justify-content-between"><span class="text-muted">Memberships</span><span class="fw-semibold">{{ number_format($snapshot['access']['active_memberships']) }}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Branches</span><span class="fw-semibold">{{ number_format($snapshot['access']['active_branches']) }}</span></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Recent registration applications</span>
                <span class="badge text-bg-light border">{{ count($recentRegistrations) }}</span>
            </div>
            <div class="card-body">
                @forelse ($recentRegistrations as $index => $registration)
                    <div class="mb-3 pb-3 border-bottom registration-item" @if ($index >= $sidePreviewLimit) hidden @endif>
                        <div class="d-flex justify-content-between align-items-start">
                            <span>{{ $registration['legal_name'] }}</span>
                            <x-status-badge :value="$registration['status']" type="status" />
                        </div>
                        <div class="text-muted small font-monospace">{{ $registration['vat_number'] ?? '—' }}</div>
                        <div class="text-muted small">{{ \Illuminate\Support\Carbon::parse($registration['submitted_at'])->format('d M Y') }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No registration applications yet.</p>
                @endforelse
                @if (count($recentRegistrations) > $sidePreviewLimit)
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" data-show-more-target="registration-item" data-show-more-count="{{ count($recentRegistrations) - $sidePreviewLimit }}">
                        Show {{ count($recentRegistrations) - $sidePreviewLimit }} more
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card">
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
    <div class="alert alert-info mb-0 rounded-0 border-0 border-top small">
        <strong>ITAS integration boundary is ready.</strong><br>
        Live federation and taxpayer verification remain disabled until NamRA/ITAS confirms the protocol, claims and authoritative response contract.
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-show-more-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.dataset.expanded === '1';
                var items = document.querySelectorAll('.' + button.dataset.showMoreTarget);
                items.forEach(function (item, index) {
                    if (index >= 5) { item.hidden = expanded; }
                });
                button.dataset.expanded = expanded ? '0' : '1';
                button.textContent = expanded
                    ? 'Show ' + button.dataset.showMoreCount + ' more'
                    : 'Show fewer';
            });
        });
    })();
</script>
@endpush

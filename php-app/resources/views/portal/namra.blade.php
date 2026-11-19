@extends('layouts.app')

@section('title', $tenantAuthorityShortName.' portal')

@php
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $openCases = collect($snapshot['compliance']['cases'])->reject(fn ($item) => $item['status'] === 'CLOSED')->count();
    $openRisks = collect($snapshot['compliance']['risks'])->where('status', 'OPEN')->count();
    $pendingApprovals = collect($snapshot['vat']['approvals'])->where('status', 'PENDING')->count();
@endphp

@section('content')
<x-page-header :title="$tenantAuthorityShortName.' portal'" :back="route('portals.index')" back-label="Portals"
    subtitle="Your audit cases, open risk signals and returns awaiting approval. Risk indicators are advisory — every adverse action needs evidence-based review." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <div class="col">
        <x-stat-tile label="Taxpayers" icon="building" :value="number_format(count($snapshot['identity']['organisations']))" hint="Active registered organisations" />
    </div>
    <div class="col">
        <x-stat-tile label="Open cases" icon="folder2-open" :value="number_format($openCases)" hint="Audit cases in progress" />
    </div>
    <div class="col">
        <x-stat-tile label="Open risk indicators" icon="activity" :value="number_format($openRisks)" hint="Awaiting officer review" :tone="$openRisks > 0 ? 'warning' : 'success'" />
    </div>
    <div class="col">
        <x-stat-tile label="Return approvals" icon="check2-square" :value="number_format($pendingApprovals)" hint="Returns waiting for a second approver" />
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Case queue</div>
        <div class="text-muted small">Audit cases by latest update</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <caption class="visually-hidden">Audit cases, their taxpayer, type, risk tier and status</caption>
            <thead>
                <tr><th scope="col">Case</th><th scope="col">Taxpayer</th><th scope="col">Type</th><th scope="col">Risk tier</th><th scope="col">Status</th><th scope="col">Updated</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['compliance']['cases'] as $item)
                    <tr>
                        <td>
                            @if (Route::has('audit-cases.show'))
                                <a href="{{ route('audit-cases.show', $item['id']) }}">{{ $item['case_number'] }}</a>
                            @else
                                <span class="fw-semibold">{{ $item['case_number'] }}</span>
                            @endif
                            <div class="text-muted small">{{ $item['title'] }}</div>
                        </td>
                        <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</td>
                        <td>@humanize($item['case_type'])</td>
                        <td><x-status-badge :value="$item['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                        <td class="text-nowrap">{{ $dateTime($item['updated_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No audit cases yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

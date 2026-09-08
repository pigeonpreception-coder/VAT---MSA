@extends('layouts.app')

@section('title', 'NamRA portal')

@php
    $dateTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $openCases = collect($snapshot['compliance']['cases'])->reject(fn ($item) => $item['status'] === 'CLOSED')->count();
    $openRisks = collect($snapshot['compliance']['risks'])->where('status', 'OPEN')->count();
    $pendingApprovals = collect($snapshot['vat']['approvals'])->where('status', 'PENDING')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">NamRA officer workspace</div>
    <h1 class="h3 mb-1">Due, abnormal, unresolved and assigned work</h1>
    <p class="text-muted mb-0">National tax data and internal indicators appear only for authorised NamRA roles. Risk indicators remain advisory and require human evidence-led review before any adverse action.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Taxpayers</span><span>T</span></div>
            <div class="fs-2 fw-semibold">{{ number_format(count($snapshot['identity']['organisations'])) }}</div>
            <div class="small text-muted">Canonical active organisations</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Open cases</span><span>C</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($openCases) }}</div>
            <div class="small text-muted">Evidence-led work queue</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Risk indicators</span><span>R</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($openRisks) }}</div>
            <div class="small {{ $openRisks > 0 ? 'text-warning' : 'text-success' }}">No automated adverse decision</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between text-muted small text-uppercase"><span>Return approvals</span><span>A</span></div>
            <div class="fs-2 fw-semibold">{{ number_format($pendingApprovals) }}</div>
            <div class="small text-muted">Maker-checker tasks</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Officer case queue</div>
        <div class="text-muted small">Purpose, assignment and classification controls apply on every action</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Audit cases, their taxpayer, type, risk tier and status</caption>
            <thead>
                <tr><th scope="col">Case</th><th scope="col">Taxpayer</th><th scope="col">Type</th><th scope="col">Risk tier</th><th scope="col">Status</th><th scope="col">Updated</th></tr>
            </thead>
            <tbody>
                @forelse ($snapshot['compliance']['cases'] as $item)
                    <tr>
                        <td><strong>{{ $item['case_number'] }}</strong><div class="text-muted small">{{ $item['title'] }}</div></td>
                        <td>{{ $item['legal_name'] ?? $item['taxpayer_id'] }}</td>
                        <td>{{ $titleCase($item['case_type']) }}</td>
                        <td><x-status-badge :value="$item['risk_tier']" type="risk" /></td>
                        <td><x-status-badge :value="$item['status']" type="status" /></td>
                        <td>{{ $dateTime($item['updated_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No audit cases yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

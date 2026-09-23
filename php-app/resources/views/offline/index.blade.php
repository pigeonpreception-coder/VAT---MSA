@extends('layouts.app')

@section('title', 'Offline continuity')

@php
    $activeRanges = collect($data['numberRanges'])->where('status', 'ACTIVE')->count();
    $rejectedBatches = collect($data['batches'])->where('status', 'REJECTED')->count();
    $openConflicts = collect($data['conflicts'])->where('status', 'OPEN')->count();
@endphp

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Business continuity</div>
    <h1 class="h3 mb-1">Offline devices, number ranges and ordered synchronisation</h1>
    <p class="text-muted mb-0">Offline documents require an enrolled device, verified public key, reserved number range, contiguous sequence, hash-chain continuity and a valid signature before fiscal processing.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Devices</div>
            <div class="fs-2 fw-semibold">{{ number_format(count($data['devices'])) }}</div>
            <div class="small text-muted">Trust bootstrap required</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Active ranges</div>
            <div class="fs-2 fw-semibold">{{ number_format($activeRanges) }}</div>
            <div class="small text-muted">Reserved document numbers</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Rejected batches</div>
            <div class="fs-2 fw-semibold">{{ number_format($rejectedBatches) }}</div>
            <div class="small text-warning">Preserved for security evidence</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Open conflicts</div>
            <div class="fs-2 fw-semibold">{{ number_format($openConflicts) }}</div>
            <div class="small text-muted">Human resolution queue</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Device registry</div>
                <div class="text-muted small">No device may self-enrol</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Offline devices, their enrolment and last sync status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Device</th>
                            <th scope="col">Organisation</th>
                            <th scope="col">Enrolment</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Last sequence</th>
                            <th scope="col">Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['devices'] as $device)
                            <tr>
                                <td>
                                    <strong>{{ $device['display_name'] }}</strong>
                                    <div class="text-muted small font-monospace">{{ $device['device_code'] }}</div>
                                </td>
                                <td>{{ $device['legal_name'] ?? $device['organisation_id'] }}</td>
                                <td><x-status-badge :value="$device['enrolment_status']" type="status" /></td>
                                <td><x-status-badge :value="$device['status']" type="status" /></td>
                                <td class="text-end">{{ number_format($device['last_accepted_sequence']) }}</td>
                                <td>{{ $device['last_seen_at'] ? \Illuminate\Support\Carbon::parse($device['last_seen_at'])->format('d M Y, H:i') : 'Never' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No offline devices are enrolled.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Number ranges</div>
                <div class="text-muted small">Held until device trust is verified</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Reserved offline number ranges</caption>
                    <thead>
                        <tr>
                            <th scope="col">Document</th>
                            <th scope="col">Prefix</th>
                            <th scope="col">Range</th>
                            <th scope="col" class="text-end">Next</th>
                            <th scope="col">Valid</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['numberRanges'] as $range)
                            <tr>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $range['document_type']))) }}</td>
                                <td class="font-monospace">{{ $range['prefix'] }}</td>
                                <td>{{ number_format($range['range_start']) }}&ndash;{{ number_format($range['range_end']) }}</td>
                                <td class="text-end">{{ number_format($range['next_number']) }}</td>
                                <td>
                                    {{ \Illuminate\Support\Carbon::parse($range['valid_from'])->format('d M Y') }}
                                    <div class="text-muted small">to {{ \Illuminate\Support\Carbon::parse($range['valid_to'])->format('d M Y') }}</div>
                                </td>
                                <td><x-status-badge :value="$range['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No number ranges are reserved.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <div class="fw-semibold">Batch intake</div>
        <div class="text-muted small">Rejected attempts remain auditable</div>
    </div>
    @if (count($data['batches']))
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <caption class="visually-hidden">Offline sync batches, their sequence range, status and reason</caption>
                <thead>
                    <tr>
                        <th scope="col">Batch</th>
                        <th scope="col">Sequence</th>
                        <th scope="col" class="text-end">Documents</th>
                        <th scope="col">Status</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Received</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['batches'] as $batch)
                        <tr>
                            <td class="font-monospace">{{ $batch['client_batch_id'] }}</td>
                            <td>{{ number_format($batch['sequence_from']) }}&ndash;{{ number_format($batch['sequence_to']) }}</td>
                            <td class="text-end">{{ number_format($batch['document_count']) }}</td>
                            <td><x-status-badge :value="$batch['status']" type="status" /></td>
                            <td>{{ $batch['rejection_reason'] ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($batch['received_at'])->format('d M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card-body">
            <p class="text-muted mb-0"><strong>No offline batches received.</strong> Batch validation is available through the versioned API.</p>
        </div>
    @endif
</div>
@endsection

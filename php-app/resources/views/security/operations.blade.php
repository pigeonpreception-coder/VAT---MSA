@extends('layouts.app')

@section('title', 'Security operations')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Security domain</div>
    <h1 class="h3 mb-1">Security operations</h1>
    <p class="text-muted mb-0">Correlated security events, detection-rule findings and the incidents they open. A detection rule fires automatically once its threshold is met within its window; an analyst can also open an incident by hand.</p>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>This action needs attention.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@can('permission', 'security:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Open an incident</h2>
            <form method="POST" action="{{ route('security.incidents.store') }}" class="row g-2">
                @csrf
                <x-idempotency-key />
                <div class="col-md-4">
                    <label for="title" class="form-label small mb-0">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}" class="form-control form-control-sm" minlength="5" maxlength="200" required>
                </div>
                <div class="col-md-2">
                    <label for="severity" class="form-label small mb-0">Severity</label>
                    <select id="severity" name="severity" class="form-select form-select-sm" required>
                        <option value="LOW" @selected(old('severity') === 'LOW')>Low</option>
                        <option value="MEDIUM" @selected(old('severity') === 'MEDIUM')>Medium</option>
                        <option value="HIGH" @selected(old('severity') === 'HIGH')>High</option>
                        <option value="CRITICAL" @selected(old('severity') === 'CRITICAL')>Critical</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="source_event_id" class="form-label small mb-0">Source event ID (optional)</label>
                    <input type="text" id="source_event_id" name="source_event_id" value="{{ old('source_event_id') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label for="subject_user_id" class="form-label small mb-0">Subject user ID (optional)</label>
                    <input type="text" id="subject_user_id" name="subject_user_id" value="{{ old('subject_user_id') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <label for="details" class="form-label small mb-0">Details</label>
                    <textarea id="details" name="details" class="form-control form-control-sm" minlength="5" maxlength="1000" rows="2" required>{{ old('details') }}</textarea>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Open incident</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card mb-3">
    <div class="card-header">
        <form method="GET" action="{{ route('security.operations') }}" class="row g-2 align-items-center">
            <div class="col-md-3">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($statusFilter === null)>All statuses</option>
                    @foreach (['OPEN', 'CONTAINED', 'CLOSED'] as $s)
                        <option value="{{ $s }}" @selected($statusFilter === $s)>{{ ucwords(strtolower($s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="severity" class="form-label small mb-0">Severity</label>
                <select id="severity" name="severity" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($severityFilter === null)>All severities</option>
                    @foreach (['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'] as $s)
                        <option value="{{ $s }}" @selected($severityFilter === $s)>{{ ucwords(strtolower($s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 text-md-end small text-muted">{{ count($incidents) }} incident{{ count($incidents) === 1 ? '' : 's' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Security incidents, filterable by status and severity</caption>
            <thead>
                <tr>
                    <th scope="col">Incident</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Status</th>
                    <th scope="col">Detection rule</th>
                    <th scope="col">Opened</th>
                    @can('permission', 'security:manage')
                        <th scope="col">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($incidents as $incident)
                    <tr>
                        <td>
                            <strong>{{ $incident['title'] }}</strong>
                            <div class="text-muted small">{{ $incident['id'] }}{{ $incident['subject_user_id'] ? ' · subject '.$incident['subject_user_id'] : '' }}</div>
                            @if ($incident['status'] === 'CLOSED' && $incident['resolution_notes'])
                                <div class="text-muted small fst-italic">Resolution: {{ $incident['resolution_notes'] }}</div>
                            @endif
                        </td>
                        <td><x-status-badge :value="$incident['severity']" type="risk" /></td>
                        <td><x-status-badge :value="$incident['status']" type="incident" /></td>
                        <td>{{ $incident['detection_rule_code'] ?? 'Manual' }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($incident['opened_at'])->format('d M Y H:i') }}</td>
                        @can('permission', 'security:manage')
                            <td>
                                @if ($incident['status'] !== 'CLOSED')
                                    <div class="d-flex flex-column gap-1">
                                        @if ($incident['status'] === 'OPEN')
                                            <form method="POST" action="{{ route('security.incidents.contain', $incident['id']) }}" class="d-flex gap-1">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Containment notes" minlength="5" maxlength="500" required>
                                                <button type="submit" class="btn btn-outline-warning btn-sm text-nowrap">Contain</button>
                                            </form>
                                        @endif
                                        @if ($incident['subject_user_id'])
                                            <form method="POST" action="{{ route('security.incidents.revoke-access', $incident['id']) }}" class="d-flex gap-1">
                                                @csrf
                                                <x-idempotency-key />
                                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Revocation notes" minlength="5" maxlength="500" required>
                                                <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Revoke access</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('security.incidents.close', $incident['id']) }}" class="d-flex gap-1">
                                            @csrf
                                            <x-idempotency-key />
                                            <input type="text" name="resolution_notes" class="form-control form-control-sm" placeholder="Resolution notes" minlength="10" maxlength="1000" required>
                                            <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">Close</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-muted small">Closed{{ $incident['closed_at'] ? ' '.\Illuminate\Support\Carbon::parse($incident['closed_at'])->format('d M Y') : '' }}</span>
                                @endif
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="text-center text-muted py-4">No security incidents match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="h6 mb-0">Recent security events</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">The most recent security events recorded across the platform</caption>
            <thead>
                <tr>
                    <th scope="col">Event type</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Action</th>
                    <th scope="col">Outcome</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Occurred</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event['event_type'] }}</td>
                        <td><x-status-badge :value="$event['severity']" type="risk" /></td>
                        <td>{{ $event['action'] }}</td>
                        <td>{{ $event['outcome'] }}</td>
                        <td class="text-muted small">{{ $event['actor_id'] ?? $event['source_token'] }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No security events recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

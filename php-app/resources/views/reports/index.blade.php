@extends('layouts.app')

@section('title', 'Reports & analytics')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Reports & analytics</div>
    <h1 class="h3 mb-1">Governed reporting, exports and certified metrics</h1>
    <p class="text-muted mb-0">Every report run reconciles to source control totals before it can be published; a sensitive export needs a second, independent approval and a fresh password confirmation.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">Report catalogue</div>
        <div class="text-muted small">Running a report re-computes it inline; nothing is official until it is published</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Active report definitions, their audience tier and classification, with a run action</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Audience</th>
                    <th scope="col">Classification</th>
                    <th scope="col">Freshness</th>
                    @if ($canRun)
                        <th scope="col">Run</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($definitions as $definition)
                    <tr>
                        <td><span class="font-monospace">{{ $definition->code }}</span></td>
                        <td>{{ $definition->name }}</td>
                        <td>{{ str_replace('_', ' ', $definition->audience) }}</td>
                        <td><x-status-badge :value="$definition->classification" type="status" /></td>
                        <td>{{ str_replace('_', ' ', $definition->freshness_tier) }}</td>
                        @if ($canRun)
                            <td>
                                <form method="POST" action="{{ route('reports.run', $definition->code) }}" class="d-flex gap-2">
                                    @csrf
                                    @if ($definition->code === 'CASE_EVIDENCE_SUMMARY')
                                        <input type="text" name="case_id" class="form-control form-control-sm" placeholder="Audit case ID" required style="width: 12rem;">
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Run</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canRun ? 6 : 5 }}" class="text-center text-muted py-4"><strong>No active report definitions.</strong></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">My report runs</div>
        <div class="text-muted small">Publish reconciles the run against live source data before it becomes official</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Report runs requested by the signed-in user, with publish and export-request actions</caption>
            <thead>
                <tr>
                    <th scope="col">Report</th>
                    <th scope="col">Status</th>
                    <th scope="col">Rows</th>
                    <th scope="col">Requested</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($myRuns as $run)
                    <tr>
                        <td><span class="font-monospace">{{ $run->code }}</span><div class="text-muted small">{{ $run->name }}</div></td>
                        <td><x-status-badge :value="$run->status" type="status" /></td>
                        <td>{{ number_format($run->row_count) }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($run->requested_at)->format('d M Y, H:i') }}</td>
                        <td class="d-flex gap-2">
                            @if ($run->status === 'COMPLETED_INLINE')
                                <form method="POST" action="{{ route('reports.publish', $run->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Publish</button>
                                </form>
                            @endif
                            @if (in_array($run->status, ['COMPLETED_INLINE', 'PUBLISHED'], true))
                                <form method="POST" action="{{ route('reports.export.request', $run->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Request export</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4"><strong>No report runs yet.</strong> Run a report from the catalogue above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <div class="fw-semibold">My exports</div>
        <div class="text-muted small">A sensitive report's export starts quarantined until an independent approval</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Report exports requested by the signed-in user, with cancel and download actions</caption>
            <thead>
                <tr>
                    <th scope="col">Report</th>
                    <th scope="col">Status</th>
                    <th scope="col">Requested</th>
                    <th scope="col">Expires</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($myExports as $export)
                    <tr>
                        <td><span class="font-monospace">{{ $export->report_code }}</span></td>
                        <td><x-status-badge :value="$export->status" type="status" /> @if ($export->requires_step_up)<span class="badge text-bg-light">Step-up</span>@endif</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($export->requested_at)->format('d M Y, H:i') }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($export->expires_at)->format('d M Y, H:i') }}</td>
                        <td class="d-flex gap-2">
                            @if ($export->status === 'PENDING_APPROVAL')
                                <form method="POST" action="{{ route('reports.export.cancel', $export->id) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="Cancelled by the requester from the reports console.">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            @endif
                            @if ($export->status === 'APPROVED')
                                <a href="{{ route('reports.export.download', $export->id) }}" class="btn btn-sm btn-outline-secondary">Download</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4"><strong>No exports requested yet.</strong></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($isNational)
    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold">Pending export approvals</div>
            <div class="text-muted small">A national role may approve or cancel a colleague's pending export, never their own</div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <caption class="visually-hidden">Report exports requested by other users awaiting national approval</caption>
                <thead>
                    <tr>
                        <th scope="col">Report</th>
                        <th scope="col">Requested</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pendingApprovals as $export)
                        <tr>
                            <td><span class="font-monospace">{{ $export->report_code }}</span> @if ($export->requires_step_up)<span class="badge text-bg-light ms-1">Step-up</span>@endif</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($export->requested_at)->format('d M Y, H:i') }}</td>
                            <td class="d-flex gap-2">
                                <form method="POST" action="{{ route('reports.export.approve', $export->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('reports.export.cancel', $export->id) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="Cancelled by a national reviewer from the reports console.">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4"><strong>Nothing pending approval.</strong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="mb-3 mt-4">
    <div class="text-uppercase text-muted small fw-semibold">Analytics</div>
    <h2 class="h4 mb-1">Certified data products</h2>
    <p class="text-muted mb-0">A model run may only be fed by an already-published, reconciled report run -- never a live query against invoices, returns or audit cases directly.</p>
</div>

<div class="row row-cols-1 row-cols-lg-2 g-3 mb-3">
    @forelse ($dataProducts as $product)
        <div class="col">
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold">{{ $product['name'] }} <span class="font-monospace text-muted small">{{ $product['code'] }}</span></div>
                    <div class="text-muted small">Source: {{ $product['source']['report_code'] }}</div>
                </div>
                <div class="card-body">
                    <p class="mb-2">{{ $product['description'] }}</p>
                    <div class="small text-muted text-uppercase mb-1">Certified metrics</div>
                    @forelse ($product['certified_metrics'] as $metric)
                        <span class="badge text-bg-light me-1 mb-1">{{ $metric['code'] }} ({{ $metric['unit'] }})</span>
                    @empty
                        <span class="text-muted small">None certified yet.</span>
                    @endforelse
                    <div class="small text-muted text-uppercase mt-2 mb-1">Latest snapshot</div>
                    @if ($product['latest_snapshot'])
                        <div class="small">Published {{ \Illuminate\Support\Carbon::parse($product['latest_snapshot']['published_at'])->format('d M Y, H:i') }}</div>
                    @else
                        <span class="text-muted small">Not yet published.</span>
                    @endif

                    @if ($isNational)
                        <hr>
                        <form method="POST" action="{{ route('reports.analytics.run-model', $product['id']) }}" class="d-flex gap-2 mb-2">
                            @csrf
                            <select class="form-select form-select-sm" name="report_run_id" required>
                                <option value="" selected disabled>Published run to model</option>
                                @foreach ($publishedRuns as $run)
                                    <option value="{{ $run->id }}">{{ $run->code }} &mdash; {{ substr($run->id, 0, 8) }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Run model</button>
                        </form>
                        <form method="POST" action="{{ route('reports.analytics.publish', $product['id']) }}" class="d-flex gap-2">
                            @csrf
                            <select class="form-select form-select-sm" name="model_run_id" required>
                                <option value="" selected disabled>Completed model run to publish</option>
                                @foreach ($publishableModelRuns as $modelRun)
                                    @if ($modelRun->data_product_code === $product['code'])
                                        <option value="{{ $modelRun->id }}">{{ substr($modelRun->id, 0, 8) }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-success text-nowrap">Publish</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col"><p class="text-muted">No data products defined.</p></div>
    @endforelse
</div>

<div class="row row-cols-1 row-cols-lg-2 g-3">
    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold">Approved metrics</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Certified metrics with their latest available value</caption>
                    <thead><tr><th scope="col">Metric</th><th scope="col">Value</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($metrics as $metric)
                            <tr>
                                <td>{{ $metric['code'] }}<div class="text-muted small">{{ $metric['data_product_code'] }}</div></td>
                                <td>{{ $metric['value'] ?? '—' }} <span class="text-muted small">{{ $metric['unit'] }}</span></td>
                                <td><x-status-badge :value="$metric['status']" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">No certified metrics.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100">
            <div class="card-header fw-semibold">Anomaly candidates</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <caption class="visually-hidden">Metric changes between snapshots that exceeded their anomaly threshold</caption>
                    <thead><tr><th scope="col">Metric</th><th scope="col">Change</th><th scope="col">Detected</th></tr></thead>
                    <tbody>
                        @forelse ($anomalies as $anomaly)
                            <tr>
                                <td>{{ $anomaly['metric_code'] }}</td>
                                <td>{{ number_format($anomaly['pct_change'], 1) }}% (threshold {{ number_format($anomaly['threshold_pct'], 1) }}%)</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($anomaly['detected_at'])->format('d M Y, H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">No anomalies detected.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

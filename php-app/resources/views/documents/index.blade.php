@extends('layouts.app')

@section('title', 'Evidence documents')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Evidence custody</div>
    <h1 class="h3 mb-1">Private documents, integrity checks and quarantine</h1>
    <p class="text-muted mb-0">Evidence objects are private, checksummed and classified. New uploads remain quarantined until a separately configured malware scanner records a clean result; this application never guesses that outcome.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Documents</div>
            <div class="fs-2 fw-semibold">{{ number_format($documents->count()) }}</div>
            <div class="small text-muted">Tenant-scoped metadata records</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Quarantined</div>
            <div class="fs-2 fw-semibold">{{ number_format($documents->where('status', 'QUARANTINED')->count()) }}</div>
            <div class="small text-warning">Not available for consumption</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Clean</div>
            <div class="fs-2 fw-semibold">{{ number_format($documents->where('scan_status', 'CLEAN')->count()) }}</div>
            <div class="small text-muted">Verified by an external scanner</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Legal holds</div>
            <div class="fs-2 fw-semibold">{{ number_format($documents->where('legal_hold', true)->count()) }}</div>
            <div class="small text-muted">Deletion protection</div>
        </div></div>
    </div>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Upload rejected.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($canUpload)
    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold">Add evidence</div>
            <div class="text-muted small">Object storage write followed by atomic metadata, audit and outbox records</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="owner_domain" class="form-label">Evidence domain</label>
                        <select class="form-select" id="owner_domain" name="owner_domain" required>
                            <option value="" disabled @selected(old('owner_domain', $defaultOwnerDomain) === '')>Select domain</option>
                            <option value="EXPENSE" @selected(old('owner_domain', $defaultOwnerDomain) === 'EXPENSE')>Expense</option>
                            <option value="IMPORT" @selected(old('owner_domain', $defaultOwnerDomain) === 'IMPORT')>Import</option>
                            <option value="AUDIT_CASE" @selected(old('owner_domain', $defaultOwnerDomain) === 'AUDIT_CASE')>Audit case</option>
                            <option value="VAT_ADJUSTMENT" @selected(old('owner_domain', $defaultOwnerDomain) === 'VAT_ADJUSTMENT')>VAT adjustment</option>
                            <option value="REFUND" @selected(old('owner_domain', $defaultOwnerDomain) === 'REFUND')>Refund</option>
                            <option value="BANK_IMPORT" @selected(old('owner_domain', $defaultOwnerDomain) === 'BANK_IMPORT')>Bank import</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="owner_resource_id" class="form-label">Owner resource ID</label>
                        <input type="text" class="form-control" id="owner_resource_id" name="owner_resource_id" required minlength="2" maxlength="100" placeholder="Resource identifier" value="{{ old('owner_resource_id', $defaultOwnerResourceId) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="classification" class="form-label">Classification</label>
                        <select class="form-select" id="classification" name="classification" required>
                            <option value="INTERNAL" @selected(old('classification') === 'INTERNAL')>Internal</option>
                            <option value="CONFIDENTIAL" @selected(old('classification') === 'CONFIDENTIAL')>Confidential</option>
                            <option value="TAX_CONFIDENTIAL" @selected(old('classification', 'TAX_CONFIDENTIAL') === 'TAX_CONFIDENTIAL')>Tax confidential</option>
                            <option value="RESTRICTED" @selected(old('classification') === 'RESTRICTED')>Restricted</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="file" class="form-label">Evidence file</label>
                    <input type="file" class="form-control" id="file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.csv,.xlsx">
                    <div class="form-text">PDF, PNG, JPEG, CSV or XLSX; maximum 10 MiB.</div>
                </div>
                <button type="submit" class="btn btn-primary">Upload to quarantine</button>
            </form>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header">
        <div class="fw-semibold">Evidence register</div>
        <div class="text-muted small">Downloads are unavailable while malware scanning is not configured</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Evidence documents, their owner, classification, scan outcome, status and size</caption>
            <thead>
                <tr>
                    <th scope="col">File</th>
                    <th scope="col">Owner</th>
                    <th scope="col">Classification</th>
                    <th scope="col">Scan</th>
                    <th scope="col">Status</th>
                    <th scope="col">Size</th>
                    <th scope="col">Uploaded</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documents as $document)
                    <tr>
                        <td>
                            <strong>{{ $document->file_name }}</strong>
                            <div class="text-muted small font-monospace">{{ substr($document->checksum_sha256, 0, 16) }}...</div>
                        </td>
                        <td>
                            {{ str_replace('_', ' ', $document->owner_domain) }}
                            <div class="text-muted small font-monospace">{{ $document->owner_resource_id }}</div>
                        </td>
                        <td><x-status-badge :value="$document->classification" type="status" /></td>
                        <td><x-status-badge :value="$document->scan_status" type="status" /></td>
                        <td><x-status-badge :value="$document->status" type="status" /></td>
                        <td>{{ number_format($document->size_bytes) }} bytes</td>
                        <td>{{ $document->uploaded_at->format('d M Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4"><strong>No evidence documents.</strong> Use the governed upload to create a quarantined evidence object.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Disputes')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain</div>
    <h1 class="h3 mb-1">Disputes</h1>
    <p class="text-muted mb-0">A taxpayer may file a dispute against an audit finding, VAT return, refund decision, or obligation -- reviewed independently of the resource being disputed.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@can('permission', 'disputes:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">File a dispute</h2>
            @if ($errors->any())
                <div class="alert alert-danger py-2" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('disputes.store') }}" class="row g-2">
                @csrf
                @if ($isNational)
                    <div class="col-md-2">
                        <label for="vat_number" class="form-label small mb-0">Taxpayer VAT number</label>
                        <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control form-control-sm @error('vat_number') is-invalid @enderror" required>
                        @error('vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif
                <div class="col-md-2">
                    <label for="disputed_resource_type" class="form-label small mb-0">Disputed resource</label>
                    <select id="disputed_resource_type" name="disputed_resource_type" class="form-select form-select-sm @error('disputed_resource_type') is-invalid @enderror" required>
                        <option value="AUDIT_FINDING" @selected(old('disputed_resource_type') === 'AUDIT_FINDING')>Audit finding</option>
                        <option value="VAT_RETURN" @selected(old('disputed_resource_type') === 'VAT_RETURN')>VAT return</option>
                        <option value="REFUND_DECISION" @selected(old('disputed_resource_type') === 'REFUND_DECISION')>Refund decision</option>
                        <option value="OBLIGATION" @selected(old('disputed_resource_type') === 'OBLIGATION')>Obligation</option>
                    </select>
                    @error('disputed_resource_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="disputed_resource_id" class="form-label small mb-0">Resource ID</label>
                    <input type="text" id="disputed_resource_id" name="disputed_resource_id" value="{{ old('disputed_resource_id') }}" class="form-control form-control-sm @error('disputed_resource_id') is-invalid @enderror" required>
                    @error('disputed_resource_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="disputed_amount" class="form-label small mb-0">Disputed amount (NAD)</label>
                    <input type="number" step="0.01" min="0" id="disputed_amount" name="disputed_amount" value="{{ old('disputed_amount') }}" class="form-control form-control-sm @error('disputed_amount_cents') is-invalid @enderror" required>
                    @error('disputed_amount_cents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="audit_case_id" class="form-label small mb-0">Related case ID (optional)</label>
                    <input type="text" id="audit_case_id" name="audit_case_id" value="{{ old('audit_case_id') }}" class="form-control form-control-sm @error('audit_case_id') is-invalid @enderror">
                    @error('audit_case_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-1 align-self-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">File</button>
                </div>
                <div class="col-12">
                    <label for="grounds" class="form-label small mb-0">Grounds</label>
                    <textarea id="grounds" name="grounds" class="form-control form-control-sm @error('grounds') is-invalid @enderror" minlength="20" maxlength="4000" rows="2" required>{{ old('grounds') }}</textarea>
                    @error('grounds')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('disputes.index') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($status === '')>All statuses</option>
                    <option value="FILED" @selected($status === 'FILED')>Filed</option>
                </select>
            </div>
            <div class="col-md-8 text-md-end small text-muted">{{ count($disputes) }} dispute{{ count($disputes) === 1 ? '' : 's' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Disputes, filterable by status</caption>
            <thead>
                <tr>
                    <th scope="col">Dispute</th>
                    <th scope="col">Taxpayer</th>
                    <th scope="col">Disputed resource</th>
                    <th scope="col" class="text-end">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Filed</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($disputes as $dispute)
                    <tr>
                        <td><a href="{{ route('disputes.show', $dispute['id']) }}"><strong>{{ $dispute['dispute_number'] }}</strong></a></td>
                        <td>
                            {{ $dispute['legal_name'] ?? '—' }}
                            <div class="text-muted small">{{ $dispute['vat_number'] ?? '' }}</div>
                        </td>
                        <td>
                            {{ ucwords(strtolower(str_replace('_', ' ', $dispute['disputed_resource_type']))) }}
                            <div class="text-muted small font-monospace">{{ $dispute['disputed_resource_id'] }}</div>
                        </td>
                        <td class="text-end">{{ $dispute['currency'] }} {{ number_format($dispute['disputed_amount_cents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$dispute['status']" type="status" /></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($dispute['filed_at'])->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No disputes match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

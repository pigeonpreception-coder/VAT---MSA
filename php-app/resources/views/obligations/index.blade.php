@extends('layouts.app')

@section('title', 'Obligations')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Compliance domain</div>
    <h1 class="h3 mb-1">Tax Obligations</h1>
    <p class="text-muted mb-0">NamRA-imposed obligations against a taxpayer -- a filing, payment, or other duty with a due date, tracked to satisfaction.</p>
</div>

@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@can('permission', 'obligations:manage')
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Create an obligation</h2>
            @if ($errors->any())
                <div class="alert alert-danger py-2" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('obligations.store') }}" class="row g-2">
                @csrf
                <div class="col-md-2">
                    <label for="vat_number" class="form-label small mb-0">Taxpayer VAT number</label>
                    <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number') }}" class="form-control form-control-sm @error('vat_number') is-invalid @enderror" required>
                    @error('vat_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="obligation_type" class="form-label small mb-0">Obligation type</label>
                    <input type="text" id="obligation_type" name="obligation_type" value="{{ old('obligation_type') }}" placeholder="VAT_RETURN" class="form-control form-control-sm @error('obligation_type') is-invalid @enderror" required>
                    @error('obligation_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="period_code" class="form-label small mb-0">Period (YYYY-MM)</label>
                    <input type="text" id="period_code" name="period_code" value="{{ old('period_code') }}" placeholder="2026-09" class="form-control form-control-sm @error('period_code') is-invalid @enderror" required>
                    @error('period_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="due_date" class="form-label small mb-0">Due date</label>
                    <input type="date" id="due_date" name="due_date" value="{{ old('due_date') }}" class="form-control form-control-sm @error('due_date') is-invalid @enderror" required>
                    @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="amount" class="form-label small mb-0">Amount (NAD)</label>
                    <input type="number" step="0.01" min="0" id="amount" name="amount" value="{{ old('amount') }}" class="form-control form-control-sm @error('amount_cents') is-invalid @enderror" required>
                    @error('amount_cents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2 align-self-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Create</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('obligations.index') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label for="status" class="form-label small mb-0">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" @selected($status === '')>All statuses</option>
                    <option value="PENDING" @selected($status === 'PENDING')>Pending</option>
                    <option value="SATISFIED" @selected($status === 'SATISFIED')>Satisfied</option>
                </select>
            </div>
            <div class="col-md-8 text-md-end small text-muted">{{ count($obligations) }} obligation{{ count($obligations) === 1 ? '' : 's' }}</div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Tax obligations, filterable by status</caption>
            <thead>
                <tr>
                    <th scope="col">Taxpayer</th>
                    <th scope="col">Type</th>
                    <th scope="col">Period</th>
                    <th scope="col">Due date</th>
                    <th scope="col" class="text-end">Amount</th>
                    <th scope="col">Status</th>
                    @if ($canManage)
                        <th scope="col">Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($obligations as $obligation)
                    <tr>
                        <td>
                            {{ $obligation['legal_name'] ?? '—' }}
                            <div class="text-muted small">{{ $obligation['vat_number'] ?? '' }}</div>
                        </td>
                        <td>{{ ucwords(strtolower(str_replace('_', ' ', $obligation['obligation_type']))) }}</td>
                        <td>{{ $obligation['period_code'] }}</td>
                        <td>
                            {{ \Illuminate\Support\Carbon::parse($obligation['due_date'])->format('d M Y') }}
                            @if ($obligation['status'] === 'PENDING' && \Illuminate\Support\Carbon::parse($obligation['due_date'])->isPast())
                                <div class="text-danger small">Overdue</div>
                            @endif
                        </td>
                        <td class="text-end">{{ $obligation['currency'] }} {{ number_format($obligation['amount_cents'] / 100, 2) }}</td>
                        <td><x-status-badge :value="$obligation['status']" type="status" /></td>
                        @if ($canManage)
                            <td>
                                @if ($obligation['status'] === 'PENDING')
                                    <form method="POST" action="{{ route('obligations.satisfaction.store', $obligation['id']) }}" class="d-flex gap-1">
                                        @csrf
                                        <input type="text" name="notes" placeholder="Satisfaction notes" minlength="10" maxlength="2000" required class="form-control form-control-sm" style="min-width: 10rem;">
                                        <button type="submit" class="btn btn-outline-success btn-sm text-nowrap">Mark satisfied</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 7 : 6 }}" class="text-center text-muted py-4">No obligations match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Accounting')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Accounting domain</div>
    <h1 class="h3 mb-1">Controlled general ledger</h1>
    <p class="text-muted mb-0">Every journal is tenant-scoped, uses integer cents and must balance before it can be posted. Source references preserve traceability to operational and fiscal evidence.</p>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Ledger accounts</div>
            <div class="fs-2 fw-semibold">{{ number_format($accounts->count()) }}</div>
            <div class="small text-muted">Controlled chart of accounts</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Journals</div>
            <div class="fs-2 fw-semibold">{{ number_format($journals->count()) }}</div>
            <div class="small text-muted">Balanced double-entry records</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Posted</div>
            <div class="fs-2 fw-semibold">{{ number_format($postedCount) }}</div>
            <div class="small text-success">Immutable after posting</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Currency</div>
            <div class="fs-2 fw-semibold">N$</div>
            <div class="small text-muted">Namibian-dollar reporting currency</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Journal register</div>
                <div class="text-muted small">POST /api/v1/accounting/journals enforces balance and idempotency</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Journal entries, their date, description, source and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Journal</th>
                            <th scope="col">Date</th>
                            <th scope="col">Description</th>
                            <th scope="col">Source</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($journals as $journal)
                            <tr>
                                <td>
                                    <strong>{{ $journal->journal_number }}</strong>
                                    <div class="text-muted small font-monospace">{{ $journal->id }}</div>
                                </td>
                                <td>{{ $journal->journal_date->toDateString() }}</td>
                                <td>{{ $journal->description }}</td>
                                <td>{{ str_replace('_', ' ', $journal->source_type) }}</td>
                                <td><x-status-badge :value="$journal->status" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No journal entries on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Chart of accounts</div>
                <div class="text-muted small">Organisation-specific posting controls</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <caption class="visually-hidden">Chart of accounts, their type, control and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">Account</th>
                            <th scope="col">Type</th>
                            <th scope="col">Control</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr>
                                <td class="font-monospace"><strong>{{ $account->code }}</strong></td>
                                <td>{{ $account->name }}</td>
                                <td>{{ $account->account_type }}</td>
                                <td>{{ str_replace('_', ' ', $account->control_type ?? 'GENERAL') }}</td>
                                <td><x-status-badge :value="$account->status" type="status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No accounts on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3" role="status">
    <strong>Posting interface is active.</strong><br>
    The governed API accepts only balanced journals and validates every account, branch and project against the authorised organisation. Interactive journal authoring and approval queues will be expanded with the VAT close workflow.
</div>
@endsection

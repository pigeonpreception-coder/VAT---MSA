@extends('layouts.app')

@section('title', 'General ledger')

@section('content')
<x-page-header title="General ledger"
    subtitle="Journals and the chart of accounts for your organisation. A journal must balance before it can be posted." />

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Ledger accounts</div>
            <div class="stat-value">{{ number_format($accounts->count()) }}</div>
            <div class="small text-muted">In your chart of accounts</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Journals</div>
            <div class="stat-value">{{ number_format($journals->count()) }}</div>
            <div class="small text-muted">Double-entry journals recorded</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Posted</div>
            <div class="stat-value">{{ number_format($postedCount) }}</div>
            <div class="small text-muted">Posted journals can't be changed</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100 stat-tile"><div class="card-body">
            <div class="stat-label">Not yet posted</div>
            <div class="stat-value">{{ number_format($journals->count() - $postedCount) }}</div>
            <div class="small text-muted">Draft journals awaiting posting</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Journal register</div>
                <div class="text-muted small">Journal entries, newest first</div>
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
                                <td>@humanize($journal->source_type)</td>
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
                <div class="text-muted small">Accounts available for posting</div>
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
                                <td>@humanize($account->account_type)</td>
                                <td>@humanize($account->control_type ?? 'GENERAL')</td>
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

<p class="small text-muted mt-3 mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Journals arrive from your connected accounting systems through the accounting API, which only accepts balanced entries. Entering journals directly on this page isn't available yet.
</p>
@endsection

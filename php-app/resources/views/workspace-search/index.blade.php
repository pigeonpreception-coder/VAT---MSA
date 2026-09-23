@extends('layouts.app')

@section('title', 'Workspace search')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Permission-aware discovery</div>
    <h1 class="h3 mb-1">Search your authorised workspace</h1>
    <p class="text-muted mb-0">Results are tenant-filtered on the server and appear only when both the relevant permission and licence entitlement permit access.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" action="{{ route('workspace-search.index') }}" class="row g-2 align-items-end mb-3">
            <div class="col-md-8">
                <label for="workspace-search" class="visually-hidden">Search records</label>
                <input type="text" class="form-control" id="workspace-search" name="q" value="{{ $query }}" minlength="2" maxlength="80" placeholder="Employee, invoice or role">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>

        @if (mb_strlen(trim($query)) < 2)
            <p class="text-muted mb-0"><strong>Enter at least two characters.</strong> Search operates only inside the active licensed organisation.</p>
        @elseif (count($results))
            <div class="list-group">
                @foreach ($results as $result)
                    <a href="{{ $result['href'] }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge text-bg-secondary me-2">{{ $result['type'] }}</span>
                            <strong>{{ $result['title'] }}</strong>
                            <p class="mb-0 small text-muted">{{ $result['subtitle'] }}</p>
                        </div>
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-muted mb-0"><strong>No authorised matches.</strong> Records outside your tenant, role or licence scope are never returned.</p>
        @endif
    </div>
</div>
@endsection

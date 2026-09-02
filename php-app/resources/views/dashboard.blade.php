@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Session</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Name</dt>
                    <dd class="col-sm-9">{{ $user->name }}</dd>
                    <dt class="col-sm-3">Role</dt>
                    <dd class="col-sm-9"><span class="badge bg-primary">{{ $user->role }}</span></dd>
                    <dt class="col-sm-3">Scope</dt>
                    <dd class="col-sm-9">
                        @if ($isNationalScope)
                            <span class="badge bg-info text-dark">National scope</span>
                        @else
                            <span class="badge bg-secondary">Taxpayer-scoped</span>
                            <span class="text-muted small">({{ $user->taxpayer_id }})</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Effective permissions ({{ count($permissions) }})</div>
            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                @foreach ($permissions as $permission)
                    <span class="badge bg-light text-dark border mb-1">{{ $permission }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

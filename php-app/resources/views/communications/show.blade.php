@extends('layouts.app')

@section('title', $thread['subject'])

@php
    $titleCase = fn (?string $value) => $value ? ucwords(strtolower(str_replace('_', ' ', $value))) : '—';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y, H:i') : '—';
    $actorName = fn (?string $id) => $id ? ($actorNames[$id] ?? $id) : '—';
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Communications</div>
        <h1 class="h3 mb-1">{{ $thread['subject'] }}</h1>
        <p class="text-muted mb-0">{{ $titleCase($thread['related_resource_type']) }} &middot; <span class="font-monospace">{{ $thread['related_resource_id'] }}</span></p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <x-status-badge :value="$thread['status']" type="status" />
        <a href="{{ route('communications.index') }}" class="btn btn-outline-secondary">&larr; Back to communications</a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card">
    <div class="card-header"><div class="fw-semibold">Thread record</div></div>
    <dl class="card-body row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-0">
        <div class="col"><dt class="text-muted small">Classification</dt><dd class="mb-0 fw-semibold">{{ $titleCase($thread['classification']) }}</dd></div>
        <div class="col"><dt class="text-muted small">Opened by</dt><dd class="mb-0 fw-semibold">{{ $actorName($thread['opened_by']) }}</dd></div>
        <div class="col"><dt class="text-muted small">Opened</dt><dd class="mb-0 fw-semibold">{{ $dateTime($thread['opened_at']) }}</dd></div>
        @if ($thread['status'] === 'CLOSED')
            <div class="col"><dt class="text-muted small">Closed by</dt><dd class="mb-0 fw-semibold">{{ $actorName($thread['closed_by']) }}</dd></div>
            <div class="col"><dt class="text-muted small">Closed</dt><dd class="mb-0 fw-semibold">{{ $dateTime($thread['closed_at']) }}</dd></div>
            <div class="col col-lg-9"><dt class="text-muted small">Closure reason</dt><dd class="mb-0">{{ $thread['closure_reason'] }}</dd></div>
        @endif
    </dl>
</div>

<div class="card mt-3">
    <div class="card-header"><div class="fw-semibold">Messages</div></div>
    <div class="card-body">
        @forelse ($messages as $msg)
            <div class="mb-3 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <strong>{{ $actorName($msg['actor_id']) }}</strong>
                    <span class="text-nowrap">
                        <span class="badge text-bg-light border">{{ $titleCase($msg['direction']) }}</span>
                        <span class="text-muted small">{{ $titleCase($msg['channel']) }}</span>
                    </span>
                </div>
                <p class="mb-1 mt-1">{{ $msg['content_summary'] }}</p>
                <time class="text-muted small">{{ $dateTime($msg['occurred_at']) }}</time>
            </div>
        @empty
            <p class="text-muted small mb-0">No messages recorded yet.</p>
        @endforelse
    </div>

    @if ($thread['status'] === 'OPEN' && $canRespond)
        <div class="card-footer bg-transparent">
            <h2 class="h6">Respond</h2>
            <form method="POST" action="{{ route('communications.responses.store', $thread['id']) }}" class="row g-2">
                @csrf
                <x-idempotency-key />
                <div class="col-md-3">
                    <label for="channel" class="form-label small mb-0">Channel</label>
                    <select id="channel" name="channel" class="form-select form-select-sm @error('channel') is-invalid @enderror" required>
                        @foreach (['PORTAL', 'EMAIL', 'SMS', 'LETTER'] as $c)
                            <option value="{{ $c }}" @selected(old('channel') === $c)>{{ ucfirst(strtolower($c)) }}</option>
                        @endforeach
                    </select>
                    @error('channel')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-9">
                    <label for="content_summary" class="form-label small mb-0">Message</label>
                    <textarea id="content_summary" name="content_summary" class="form-control form-control-sm @error('content_summary') is-invalid @enderror" minlength="10" maxlength="4000" rows="2" required>{{ old('content_summary') }}</textarea>
                    @error('content_summary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Send response</button>
                </div>
            </form>
        </div>
    @endif
</div>

@if ($thread['status'] === 'OPEN' && $canManage)
    <div class="card mt-3">
        <div class="card-header"><div class="fw-semibold">Close thread</div></div>
        <div class="card-body">
            <form method="POST" action="{{ route('communications.closure.store', $thread['id']) }}" class="row g-2" onsubmit="return confirm('Close this correspondence thread? No further replies will be accepted.');">
                @csrf
                <x-idempotency-key />
                <div class="col-md-9">
                    <label for="reason" class="form-label small mb-0">Closure reason</label>
                    <textarea id="reason" name="reason" class="form-control form-control-sm @error('reason') is-invalid @enderror" minlength="10" maxlength="2000" rows="2" required>{{ old('reason') }}</textarea>
                    @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Close thread</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection

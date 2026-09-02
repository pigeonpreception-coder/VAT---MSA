@extends('layouts.app')

@section('title', 'Access denied')

{{--
    Red team finding RT-002 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): a plain
    Illuminate\Auth\Access\AuthorizationException (thrown by both
    TenantScope::requireTaxpayer() and every $this->authorize() gate denial)
    fell through to Laravel's default exception handler, which leaks a full
    stack trace and local filesystem path whenever APP_DEBUG=true -- unlike
    every one of this app's own custom exceptions (PlatformResourceException
    and friends), which already render cleanly regardless of debug mode.

    This view -- rendered by the AuthorizationException render callback in
    bootstrap/app.php for non-JSON requests -- gives browser-reachable
    authorization denials the same clean, debug-mode-independent treatment,
    on-brand with the rest of the UI rather than a bare framework error page.
--}}

@section('content')
<div class="alert alert-danger" role="alert">
    <h1 class="h4 alert-heading mb-2">Access denied</h1>
    <p class="mb-0">{{ $message ?: 'You are not authorised to view this resource.' }}</p>
</div>

<p><a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">&larr; Back to dashboard</a></p>
@endsection

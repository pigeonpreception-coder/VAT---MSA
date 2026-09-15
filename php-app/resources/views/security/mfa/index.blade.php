@extends('layouts.app')

@section('title', 'Security')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Account security</div>
    <h1 class="h3 mb-1">Multi-Factor Authentication</h1>
    <p class="text-muted mb-0">A real, server-verified RFC 6238 TOTP credential for your own account -- the standard "authenticator app" second factor.</p>
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

@if ($redirectTo)
    <div class="alert alert-info" role="alert">
        This action needs a fresh step-up confirmation. {{ $enrolled ? 'Confirm a current code below to continue.' : 'Finish enrolling below, then confirm a code, to continue.' }}
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted">Status</h2>
                @if ($enrolled)
                    <p class="mb-1"><span class="badge bg-success">Enabled</span> Multi-factor authentication is active on your account.</p>
                @elseif ($credentialStatus === 'PENDING_VERIFICATION')
                    <p class="mb-1"><span class="badge bg-warning text-dark">Pending verification</span> Enrolment has started but not been confirmed yet.</p>
                @else
                    <p class="mb-1"><span class="badge bg-secondary">Not enabled</span> No multi-factor credential is enrolled for your account.</p>
                @endif
                <p class="mb-0 small text-muted">Recent step-up: <strong>{{ $hasRecentStepUp ? 'Fresh (within the last few minutes)' : 'Not confirmed recently' }}</strong></p>
            </div>
        </div>

        @if (! $enrolled)
            @if ($freshSecret)
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted">Finish enrolling</h2>
                        <p class="small">Add this account to your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code it shows.</p>
                        <dl class="row small mb-3">
                            <dt class="col-sm-3">Secret key</dt>
                            <dd class="col-sm-9"><code>{{ $freshSecret }}</code></dd>
                            <dt class="col-sm-3">Setup link</dt>
                            <dd class="col-sm-9 text-break"><code>{{ $freshOtpauthUri }}</code></dd>
                        </dl>
                        <p class="small text-muted">This secret is shown only once -- if you navigate away before finishing, use "Start over" below to get a fresh one.</p>
                        <form method="POST" action="{{ route('security.mfa.verify') }}" class="row g-2 align-items-end">
                            @csrf
                            <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                            <div class="col-auto">
                                <label for="verify-code" class="form-label small mb-0">6-digit code</label>
                                <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" id="verify-code" name="code" required autocomplete="one-time-code">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Verify &amp; enable</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
            <form method="POST" action="{{ route('security.mfa.enroll') }}">
                @csrf
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                <button type="submit" class="btn {{ $freshSecret ? 'btn-outline-secondary' : 'btn-primary' }} btn-sm">
                    {{ $freshSecret ? 'Start over with a new secret' : 'Enable multi-factor authentication' }}
                </button>
            </form>
        @endif
    </div>

    @if ($enrolled)
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Confirm step-up</h2>
                    <p class="small text-muted">Enter a current code from your authenticator app to confirm a fresh step-up -- the real replacement for the client-asserted headers this application used to trust. Every sensitive action across the app (taxpayer suspension, registration decisions, access-rights grants, and more) now requires this to be fresh.</p>
                    <form method="POST" action="{{ route('security.step-up') }}" class="row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                        <div class="col-auto">
                            <label for="step-up-code" class="form-label small mb-0">6-digit code</label>
                            <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" id="step-up-code" name="code" required autocomplete="one-time-code">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-outline-primary">{{ $redirectTo ? 'Confirm & continue' : 'Confirm step-up' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 text-uppercase text-muted mb-0">Active sessions</h2>
            @if ($sessions->count() > 1)
                <form method="POST" action="{{ route('security.sessions.revoke-others') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm">Log out other sessions</button>
                </form>
            @endif
        </div>
        <p class="small text-muted">Every device currently signed in to your account. If you don't recognise one, revoke it -- it will need to sign in again with your password{{ $enrolled ? ' and a fresh MFA code' : '' }}.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr><th>Device / browser</th><th>IP address</th><th>Last active</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td class="text-break">
                                {{ $session['user_agent'] ?: 'Unknown device' }}
                                @if ($session['is_current'])
                                    <span class="badge bg-primary ms-1">This device</span>
                                @endif
                            </td>
                            <td>{{ $session['ip_address'] ?: '-' }}</td>
                            <td>{{ $session['last_activity']->diffForHumans() }}</td>
                            <td class="text-end">
                                @unless ($session['is_current'])
                                    <form method="POST" action="{{ route('security.sessions.revoke', $session['id']) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Revoke</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted small">No active sessions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

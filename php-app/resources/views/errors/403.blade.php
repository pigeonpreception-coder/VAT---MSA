@extends('layouts.app')

@section('title', 'Access denied')

{{--
    Red team finding RT-002 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): rendered
    by the AuthorizationException render callback in bootstrap/app.php for
    non-JSON requests, so authorization denials get a clean, debug-mode-
    independent page instead of a framework error page with a stack trace.
    The underlying reason stays on the page as small reference text for
    support -- it names the missing permission.
--}}

@php
    // Licence and access-review denials come through here too
    // (App\Support\Licensing\LicenseResolver / EntitlementGate); say what
    // actually blocks the user instead of implying a missing permission.
    $reason = (string) ($message ?? '');
    $isLicence = str_contains($reason, 'no configured licence') || str_contains($reason, 'No licensed organisation')
        || preg_match('/^(FEATURE_NOT_ENTITLED|LICENSE_[A-Z_]+|GRACE_PERIOD_NO_EXPANSION|ENTITLEMENT_LIMIT_EXCEEDED):/', $reason) === 1;
    $isAccessReview = str_starts_with($reason, 'QUARTERLY_ACCESS_REVIEW_REQUIRED');
    $isOrganisation = str_contains($reason, 'organisation membership') || str_contains($reason, 'organisation scope')
        || str_contains($reason, 'outside your authorised scope');
    [$heading, $guidance, $icon] = match (true) {
        $isLicence => [
            'This area isn\'t included in your licence',
            'Your organisation\'s licence doesn\'t cover this area at the moment. An administrator can check the plan under Licensing & subscription.',
            'award',
        ],
        $isAccessReview => [
            'Start this quarter\'s access review first',
            'Changes to people and roles are paused until the current quarterly access review is open. Start it in Access governance, then try again.',
            'clipboard-check',
        ],
        $isOrganisation => [
            'Your account isn\'t linked to an organisation here',
            'This area works within an organisation. Ask your administrator to add you to the right organisation.',
            'building',
        ],
        default => [
            'You don\'t have access to this page',
            'Your role doesn\'t include permission for this area. If you need it for your work, ask your organisation administrator to grant access.',
            'lock',
        ],
    };
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">
        <div class="card mt-lg-4">
            <div class="card-body p-4 p-md-5 text-center">
                <span class="icon-circle icon-circle-warning mb-3"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
                <h1 class="h4 mb-2">{{ $heading }}</h1>
                <p class="text-muted mb-4">{{ $guidance }}</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left" aria-hidden="true"></i> Go back</a>
                    @if ($isAccessReview && auth()->user()?->hasAppPermission('access-governance:read'))
                        <a href="{{ route('access-governance.index') }}" class="btn btn-primary">Open access governance</a>
                    @else
                        <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to dashboard</a>
                    @endif
                </div>
                <p class="small text-muted mt-4 mb-0">Reference: {{ $reason !== '' ? $reason : 'You are not authorised to view this resource.' }}</p>
            </div>
        </div>
    </div>
</div>
@endsection

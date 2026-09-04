@props(['value', 'type' => 'status'])

@php
    // Bootstrap 5.3's text-bg-* utilities pick a WCAG-AA-contrast-safe
    // text color for their background automatically (unlike pairing
    // e.g. badge bg-light text-dark by hand) -- every mapping below uses
    // one, so no combination here needs a manual contrast check.
    $statusMap = [
        'CERTIFIED' => 'text-bg-success', 'ACTIVE' => 'text-bg-success', 'APPROVED' => 'text-bg-success', 'APPLIED' => 'text-bg-success',
        'OPEN' => 'text-bg-success', 'ACKNOWLEDGED' => 'text-bg-success', 'FILED' => 'text-bg-success',
        'PASS' => 'text-bg-success', 'PAYMENT_PENDING' => 'text-bg-success', 'PRESERVED' => 'text-bg-success', 'SATISFIED' => 'text-bg-success',
        'MATCHED' => 'text-bg-info', 'PENDING' => 'text-bg-info', 'PENDING_APPROVAL' => 'text-bg-info', 'AWAITING_PROVIDER' => 'text-bg-info',
        'RECEIVED' => 'text-bg-info', 'RISK_REVIEW' => 'text-bg-info', 'OFFICER_REVIEW' => 'text-bg-info', 'PAYMENT_AUTHORISATION' => 'text-bg-info',
        'PROPOSED' => 'text-bg-info', 'AUTHORIZED' => 'text-bg-info', 'ASSIGNED' => 'text-bg-info', 'PLANNING' => 'text-bg-info',
        'EVIDENCE_COLLECTION' => 'text-bg-info', 'ANALYSIS' => 'text-bg-info', 'FINDINGS_REVIEW' => 'text-bg-info', 'PRELIMINARY' => 'text-bg-info',
        'EXCEPTION' => 'text-bg-danger', 'REJECTED' => 'text-bg-danger', 'REJECTED_BY_PROVIDER' => 'text-bg-danger', 'FAIL' => 'text-bg-danger',
        'CANCELLED' => 'text-bg-secondary', 'RETIRED' => 'text-bg-secondary', 'LOCKED' => 'text-bg-secondary',
        'DRAFT' => 'text-bg-secondary', 'SUPERSEDED' => 'text-bg-secondary', 'ON_HOLD' => 'text-bg-secondary',
        'CLOSED' => 'text-bg-secondary', 'NOT_CONFIGURED' => 'text-bg-secondary',
        'BLOCKED_CONFIGURATION' => 'text-bg-warning', 'BLOCKED_RETURN_NOT_FILED' => 'text-bg-warning',
        'EVIDENCE_REQUESTED' => 'text-bg-warning', 'DISPUTED' => 'text-bg-warning',
        'TAXPAYER_RESPONSE' => 'text-bg-warning', 'DECISION' => 'text-bg-warning', 'SUSPENDED' => 'text-bg-warning',
    ];
    $riskMap = [
        'LOW' => 'text-bg-success',
        'MEDIUM' => 'text-bg-info',
        'HIGH' => 'text-bg-warning',
        'CRITICAL' => 'text-bg-danger',
    ];
    // A separate map, not folded into $statusMap: 'OPEN' means something
    // genuinely different for a risk indicator (an unaddressed signal --
    // needs attention) than it does for a VAT period (a normal, healthy
    // state) -- the same string can't share one badge colour across both
    // without misleading whichever context it doesn't fit.
    $indicatorMap = [
        'OPEN' => 'text-bg-warning',
        'UNDER_REVIEW' => 'text-bg-info',
        'ESCALATED_TO_CASE' => 'text-bg-danger',
        'DISMISSED' => 'text-bg-secondary',
    ];
    $class = match ($type) {
        'risk' => $riskMap[$value] ?? 'text-bg-light',
        'indicator' => $indicatorMap[$value] ?? 'text-bg-light',
        default => $statusMap[$value] ?? 'text-bg-light',
    };
    $label = ucwords(strtolower(str_replace('_', ' ', (string) $value)));
@endphp

<span {{ $attributes->merge(['class' => "badge {$class}"]) }}>{{ $label }}</span>

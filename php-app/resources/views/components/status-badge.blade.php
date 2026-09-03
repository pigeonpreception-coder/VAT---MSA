@props(['value', 'type' => 'status'])

@php
    // Bootstrap 5.3's text-bg-* utilities pick a WCAG-AA-contrast-safe
    // text color for their background automatically (unlike pairing
    // e.g. badge bg-light text-dark by hand) -- every mapping below uses
    // one, so no combination here needs a manual contrast check.
    $statusMap = [
        'CERTIFIED' => 'text-bg-success', 'ACTIVE' => 'text-bg-success', 'APPROVED' => 'text-bg-success', 'APPLIED' => 'text-bg-success',
        'OPEN' => 'text-bg-success', 'ACKNOWLEDGED' => 'text-bg-success', 'FILED' => 'text-bg-success',
        'MATCHED' => 'text-bg-info', 'PENDING' => 'text-bg-info', 'PENDING_APPROVAL' => 'text-bg-info', 'AWAITING_PROVIDER' => 'text-bg-info',
        'EXCEPTION' => 'text-bg-danger', 'REJECTED' => 'text-bg-danger', 'REJECTED_BY_PROVIDER' => 'text-bg-danger',
        'CANCELLED' => 'text-bg-secondary', 'RETIRED' => 'text-bg-secondary', 'LOCKED' => 'text-bg-secondary',
        'DRAFT' => 'text-bg-secondary', 'SUPERSEDED' => 'text-bg-secondary',
        'BLOCKED_CONFIGURATION' => 'text-bg-warning',
    ];
    $riskMap = [
        'LOW' => 'text-bg-success',
        'MEDIUM' => 'text-bg-info',
        'HIGH' => 'text-bg-warning',
        'CRITICAL' => 'text-bg-danger',
    ];
    $class = $type === 'risk'
        ? ($riskMap[$value] ?? 'text-bg-light')
        : ($statusMap[$value] ?? 'text-bg-light');
    $label = ucwords(strtolower(str_replace('_', ' ', (string) $value)));
@endphp

<span {{ $attributes->merge(['class' => "badge {$class}"]) }}>{{ $label }}</span>

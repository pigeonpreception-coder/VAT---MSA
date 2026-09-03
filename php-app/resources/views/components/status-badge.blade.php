@props(['value', 'type' => 'status'])

@php
    // Bootstrap 5.3's text-bg-* utilities pick a WCAG-AA-contrast-safe
    // text color for their background automatically (unlike pairing
    // e.g. badge bg-light text-dark by hand) -- every mapping below uses
    // one, remapped in resources/css/app.css to the source's own four
    // status colours (green/teal/amber/red -- see that file's own doc
    // comment). The groupings below are the source's own literal
    // app/globals.css `.status-*` class list (components/PageHeader.tsx's
    // StatusBadge lowercases/hyphenates the raw value into that class
    // name): certified/matched/filed/active -> green, exception/high/
    // critical/open/denied/failed -> red, processing/medium/draft/
    // under-review/investigating/pending -> amber, low/received/success
    // -> teal. APPROVED/APPLIED/REJECTED/CANCELLED/RETIRED aren't in that
    // literal list (the source's CSS only names statuses its own limited
    // screens actually render) but are kept as reasonable, uncontradicted
    // defaults in the same spirit.
    $statusMap = [
        'CERTIFIED' => 'text-bg-success', 'MATCHED' => 'text-bg-success', 'FILED' => 'text-bg-success', 'ACTIVE' => 'text-bg-success',
        'APPROVED' => 'text-bg-success', 'APPLIED' => 'text-bg-success',
        'EXCEPTION' => 'text-bg-danger', 'OPEN' => 'text-bg-danger', 'DENIED' => 'text-bg-danger', 'FAILED' => 'text-bg-danger', 'REJECTED' => 'text-bg-danger',
        'PROCESSING' => 'text-bg-warning', 'DRAFT' => 'text-bg-warning', 'UNDER_REVIEW' => 'text-bg-warning', 'INVESTIGATING' => 'text-bg-warning', 'PENDING' => 'text-bg-warning', 'PENDING_APPROVAL' => 'text-bg-warning',
        'RECEIVED' => 'text-bg-info', 'SUCCESS' => 'text-bg-info',
        'CANCELLED' => 'text-bg-secondary', 'RETIRED' => 'text-bg-secondary',
    ];
    // Risk severity, matching the source's own status-low/-high/-critical
    // groups exactly -- HIGH and CRITICAL are both red in the source
    // (grouped together, not a separate shade each), not a fidelity gap.
    $riskMap = [
        'LOW' => 'text-bg-info',
        'MEDIUM' => 'text-bg-warning',
        'HIGH' => 'text-bg-danger',
        'CRITICAL' => 'text-bg-danger',
    ];
    $class = $type === 'risk'
        ? ($riskMap[$value] ?? 'text-bg-light')
        : ($statusMap[$value] ?? 'text-bg-light');
    $label = ucwords(strtolower(str_replace('_', ' ', (string) $value)));
@endphp

<span {{ $attributes->merge(['class' => "badge {$class}"]) }}>{{ $label }}</span>

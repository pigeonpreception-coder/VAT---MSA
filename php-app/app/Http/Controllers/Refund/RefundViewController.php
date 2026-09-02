<?php

namespace App\Http\Controllers\Refund;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/refunds/page.tsx -- the refund claim
 * workflow register. Reuses App\Services\Compliance\
 * ComplianceSnapshotService::getSnapshot for its `refunds` slice, exactly
 * like the source's own RefundsPage reuses getComplianceSnapshot rather
 * than a dedicated "list refund claims" read (App\Http\Controllers\
 * Refund\RefundController's own JSON surface has no list route either --
 * only per-claim checks/transition/dispute -- matching the source's own
 * app/api/v1/refunds/** shape, which likewise has no GET list route).
 */
class RefundViewController extends Controller
{
    public function __construct(private readonly ComplianceSnapshotService $snapshot) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'refunds:read');

        return view('refunds.index', [
            'snapshot' => $this->snapshot->getSnapshot($request->user()),
        ]);
    }
}

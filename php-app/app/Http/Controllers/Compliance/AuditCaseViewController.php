<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/cases/page.tsx -- the audit-case
 * register, findings and advisory risk indicators. Reuses
 * App\Services\Compliance\ComplianceSnapshotService::getSnapshot, the
 * same aggregate App\Http\Controllers\Compliance\ComplianceSnapshotController
 * already serves at /api/v1/compliance, not a second query path -- matching
 * App\Http\Controllers\Invoice\InvoiceViewController's own precedent of a
 * dedicated view controller sitting alongside its JSON API sibling.
 * `cases:manage` (not `compliance:read`) gates this page, matching the
 * source's own permission for this specific screen.
 */
class AuditCaseViewController extends Controller
{
    public function __construct(private readonly ComplianceSnapshotService $snapshot) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'cases:manage');

        return view('compliance.cases', [
            'snapshot' => $this->snapshot->getSnapshot($request->user()),
        ]);
    }
}

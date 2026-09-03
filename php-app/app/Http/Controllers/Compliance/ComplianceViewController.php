<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/compliance/page.tsx -- obligations,
 * disputes, secure communications and consent/delegation. Reuses
 * App\Services\Compliance\ComplianceSnapshotService::getSnapshot, the
 * same aggregate the JSON App\Http\Controllers\Compliance\
 * ComplianceSnapshotController already serves at /api/v1/compliance --
 * see App\Http\Controllers\Compliance\AuditCaseViewController's own doc
 * comment for why this is a separate view controller, not folded into it
 * (the source keeps /cases and /compliance as two distinct pages behind
 * two distinct permissions, cases:manage and compliance:read).
 */
class ComplianceViewController extends Controller
{
    public function __construct(private readonly ComplianceSnapshotService $snapshot) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');

        return view('compliance.index', [
            'snapshot' => $this->snapshot->getSnapshot($request->user()),
        ]);
    }
}

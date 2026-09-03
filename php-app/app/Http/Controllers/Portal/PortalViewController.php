<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portals/page.tsx ("Portal
 * switchboard") -- reuses App\Services\Portal\PortalService::
 * getAvailablePortals, the same read the JSON App\Http\Controllers\
 * Portal\PortalController already serves at /api/v1/portals, not a
 * second query path. Gated on `dashboard:read` alone, matching the
 * source exactly: the answer is inherently self-scoped to the caller's
 * own role and capabilities, so no stronger permission is needed to see
 * "which of these am I allowed to open".
 *
 * Each portal's `href` in the source points at its own dedicated
 * dashboard (app/portal/buyer/page.tsx and five siblings) -- each one a
 * genuinely separate, comparably-sized initiative this migration has not
 * built yet (tracked in docs/MIGRATION_MATRIX.md's frontend build-out
 * section). Rather than a dead `href="#"` link (this migration's own
 * established precedent -- see DashboardController's doc comment on the
 * removed "+ Submit invoice" button), every "Open X" button here points
 * at the one real authenticated landing page this port currently has,
 * `route('dashboard')`, until those six portal dashboards exist.
 */
class PortalViewController extends Controller
{
    public function __construct(private readonly PortalService $portals) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'dashboard:read');

        return view('portals.index', [
            'portals' => $this->portals->getAvailablePortals($request->user()),
        ]);
    }
}

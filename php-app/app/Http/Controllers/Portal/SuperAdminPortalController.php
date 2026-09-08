<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use App\Services\Portal\PortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/super-admin/page.tsx -- the
 * fourth of the six per-portal dashboards, and the second (after NamRA)
 * needing zero new backend query: `App\Services\Platform\
 * PlatformSnapshotService::getTechnicalSnapshot()` already returns
 * exactly what this page reads (`components`, `integrations`, `outbox`,
 * `securityEvents`), the same method
 * `App\Http\Controllers\Platform\PlatformSnapshotController::show`
 * already routes `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN` to.
 *
 * Gate is deliberately `platform:read`, not the `dashboard:read` every
 * sibling portal controller built so far uses: the source's own
 * `lib/portals.ts` `PORTAL_PERMISSIONS` map names `platform:read` for
 * `super-admin` specifically (`dashboard:read` for buyer/seller/namra is
 * what made those three controllers' checks effectively redundant with
 * `PortalService::getAvailablePortals()` alone -- `dashboard:read` is
 * unconditional for every role). Here the distinction is load-bearing:
 * `SECURITY_ANALYST` is on `PortalDefinitions`' own `super-admin` role
 * list but does not hold `platform:read` (`Permissions::ROLE_PERMISSIONS`
 * confirms it), so the source's own `requirePortalAccess` denies that
 * role even though `PortalService::getAvailablePortals()` alone (which
 * only replicates the role/capability check, not the source's further
 * `PORTAL_PERMISSIONS` permission gate) would not catch that -- see
 * docs/MIGRATION_MATRIX.md's own note on this gap for the other two
 * as-yet-unbuilt portals it also affects.
 */
class SuperAdminPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly PlatformSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'platform:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('super-admin')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the Super Administration portal in the active organisation context.");
        }

        return view('portal.super-admin', [
            'snapshot' => $this->snapshot->getTechnicalSnapshot(),
        ]);
    }
}

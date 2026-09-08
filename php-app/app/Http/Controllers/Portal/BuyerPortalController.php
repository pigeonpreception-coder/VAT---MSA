<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\BuyerPortalSnapshotService;
use App\Services\Portal\PortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/buyer/page.tsx -- the first of
 * the six per-portal dashboards PortalViewController's own doc comment
 * tracked as not-yet-built. Gate matches lib/portals.ts's
 * requirePortalAccess(user, "buyer") exactly: `dashboard:read` (the
 * permission PORTAL_PERMISSIONS.buyer names -- already granted to every
 * one of this migration's 22 roles, same as the portal switchboard's own
 * gate) plus the same role/Buyer-capability membership check
 * PortalService::getAvailablePortals already computes for the
 * switchboard, reused here rather than re-deriving it -- an actor who
 * cannot see the Buyer card there is refused this page too. The source's
 * own further `requireLicensedPermission` entitlement/license check is
 * not reproduced, matching DashboardController's own documented
 * `dashboard:read`-alone precedent for the same reason.
 */
class BuyerPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly BuyerPortalSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'dashboard:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('buyer')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the Buyer portal in the active organisation context.");
        }

        return view('portal.buyer', [
            'snapshot' => $this->snapshot->snapshot($user),
        ]);
    }
}

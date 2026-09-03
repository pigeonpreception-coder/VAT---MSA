<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\AuthorityGovernance\AuthorityGovernanceService;
use App\Services\Identity\IdentityFoundationSnapshotService;
use App\Services\Portal\PortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/namra-admin/page.tsx -- the
 * sixth and final per-portal dashboard, the one deliberately deferred
 * out of every other portal's own slice until
 * App\Services\AuthorityGovernance\AuthorityGovernanceService existed
 * (see docs/MIGRATION_MATRIX.md's own "NamRA Administration portal:
 * deferred" section). Reuses that service's `getSnapshot` and
 * `App\Services\Identity\IdentityFoundationSnapshotService::getSnapshot`
 * directly -- the exact two reads the source's own
 * `Promise.all([getIdentityFoundationSnapshot, getAuthorityGovernanceSnapshot])`
 * makes, no second query path.
 *
 * Gate is `authority-governance:read` (the source's own
 * `PORTAL_PERMISSIONS['namra-admin']`), matching the same pattern
 * `SuperAdminPortalController`/`DeveloperPortalController` already
 * established for `platform:read`/`developer:read`.
 *
 * Read-only, matching every other portal dashboard's own precedent: the
 * source's own interactive `AuthorityGovernanceActions` component (an
 * onboarding-case submission/decision form) is not ported here --
 * `App\Http\Controllers\AuthorityGovernance\AuthorityGovernanceController`'s
 * JSON create/decide routes are real and tested
 * (tests/Feature/AuthorityGovernance/AuthorityGovernanceTest.php), just
 * not yet wired to a form on this page, the same gap every other portal
 * dashboard's own backend commands (expense approval, quotation
 * lifecycle, ...) already carry.
 */
class NamraAdminPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly AuthorityGovernanceService $governance,
        private readonly IdentityFoundationSnapshotService $identity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'authority-governance:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('namra-admin')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the NamRA Administration portal in the active organisation context.");
        }

        return view('portal.namra-admin', [
            'governance' => $this->governance->getSnapshot($user),
            'identity' => $this->identity->getSnapshot($user),
        ]);
    }
}

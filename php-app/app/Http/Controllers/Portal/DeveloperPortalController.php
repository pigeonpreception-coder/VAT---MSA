<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use App\Services\Portal\PortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/developer/page.tsx -- the
 * fifth of the six per-portal dashboards, and the third (after NamRA and
 * Super Administration) needing zero new backend query:
 * `App\Services\Platform\PlatformSnapshotService::developerPortalSnapshot()`
 * already returns exactly `clients`/`webhooks`.
 *
 * Gate is `developer:read`, not `dashboard:read` -- see
 * `App\Http\Controllers\Portal\SuperAdminPortalController`'s own doc
 * comment for the full rationale (and docs/MIGRATION_MATRIX.md's Super
 * Administration section for the general pattern this is the second
 * instance of). `SELLER_ADMIN` is on `PortalDefinitions`' own
 * `developer` role list but does not hold `developer:read`
 * (`Permissions::ROLE_PERMISSIONS` confirms it), so the source denies
 * that role even though role/capability membership alone would not
 * catch it.
 */
class DeveloperPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly PlatformSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'developer:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('developer')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the Developer portal in the active organisation context.");
        }

        return view('portal.developer', [
            'snapshot' => $this->snapshot->developerPortalSnapshot($user),
        ]);
    }
}

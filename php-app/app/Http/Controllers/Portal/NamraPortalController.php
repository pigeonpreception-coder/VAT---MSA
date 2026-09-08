<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\NamraPortalSnapshotService;
use App\Services\Portal\PortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/namra/page.tsx -- the third of
 * the six per-portal dashboards. See
 * App\Http\Controllers\Portal\BuyerPortalController's own doc comment
 * for the full rationale behind this gate: `dashboard:read`
 * (`PORTAL_PERMISSIONS.namra` in the source) plus membership in
 * `PortalService::getAvailablePortals()`, reused rather than re-derived,
 * thrown as a real `AuthorizationException` so it renders through this
 * app's own `errors/403.blade.php`.
 */
class NamraPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly NamraPortalSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'dashboard:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('namra')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the NamRA portal in the active organisation context.");
        }

        return view('portal.namra', [
            'snapshot' => $this->snapshot->snapshot($user),
        ]);
    }
}

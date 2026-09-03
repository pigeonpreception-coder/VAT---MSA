<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalService;
use App\Services\Portal\SellerPortalSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/seller/page.tsx -- the second
 * of the six per-portal dashboards. See
 * App\Http\Controllers\Portal\BuyerPortalController's own doc comment
 * for the full rationale behind this gate: `dashboard:read`
 * (`PORTAL_PERMISSIONS.seller` in the source) plus membership in
 * `PortalService::getAvailablePortals()`, reused rather than re-derived,
 * thrown as a real `AuthorizationException` so it renders through this
 * app's own `errors/403.blade.php`.
 */
class SellerPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly SellerPortalSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'dashboard:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('seller')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the Seller portal in the active organisation context.");
        }

        return view('portal.seller', [
            'snapshot' => $this->snapshot->snapshot($user),
        ]);
    }
}

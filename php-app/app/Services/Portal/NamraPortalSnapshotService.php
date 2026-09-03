<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Compliance\ComplianceSnapshotService;
use App\Services\Identity\IdentityFoundationSnapshotService;
use App\Services\VatLifecycle\VatLifecycleService;

/**
 * Ported from the source's own app/portal/namra/page.tsx -- the third of
 * the six per-portal dashboards, and the simplest of the three built so
 * far: unlike Buyer/Seller (both organisation-scoped), every read this
 * page needs already exists as a national-scope-aware snapshot service,
 * so this class is pure composition with no new query of its own.
 * `identity`/`compliance`/`vat` reuse App\Services\Identity\
 * IdentityFoundationSnapshotService::getSnapshot,
 * App\Services\Compliance\ComplianceSnapshotService::getSnapshot and
 * App\Services\VatLifecycle\VatLifecycleService::snapshot directly --
 * the exact reads the source's own getIdentityFoundationSnapshot/
 * getComplianceSnapshot/getVatLifecycleSnapshot calls make, matching
 * this migration's own established "no second query path" precedent
 * (BuyerPortalSnapshotService/SellerPortalSnapshotService already set
 * it for the sibling portals). Same "no field-level permission check
 * beyond the portal-level gate" precedent too: the source's own
 * NamraPortalPage calls these repository functions directly after
 * `requirePortalAccess(user, "namra")` passes.
 */
class NamraPortalSnapshotService
{
    public function __construct(
        private readonly IdentityFoundationSnapshotService $identity,
        private readonly ComplianceSnapshotService $compliance,
        private readonly VatLifecycleService $vatLifecycle,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $actor): array
    {
        return [
            'identity' => $this->identity->getSnapshot($actor),
            'compliance' => $this->compliance->getSnapshot($actor),
            'vat' => $this->vatLifecycle->snapshot($actor),
        ];
    }
}

<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotService;
use App\Services\VatLifecycle\VatLifecycleService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Ported from the source's own app/portal/seller/page.tsx -- specifically
 * the subset of lib/data/business-repository.ts's getBusinessPlatformSnapshot
 * this one page reads (`business.quotations.length` and
 * `business.metrics.quoted_value_cents`), not that function's full
 * 12-query aggregate -- see App\Services\Portal\BuyerPortalSnapshotService's
 * own doc comment for why. `dashboard`/`vat` reuse
 * App\Services\Dashboard\DashboardSnapshotService::snapshot and
 * App\Services\VatLifecycle\VatLifecycleService::snapshot directly, the
 * exact reads the source's own getDashboardSnapshot/getVatLifecycleSnapshot
 * calls make -- no second query path (BuyerPortalSnapshotService already
 * established this precedent for the sibling Buyer portal).
 *
 * `quotations.count` is a plain `COUNT(*)`, not a literal reproduction of
 * the source's own `business.quotations.length` (which caps at the
 * mega-snapshot's own `LIMIT 100` on the underlying list) -- at this
 * pilot's realistic data volumes the two are identical, and fetching up
 * to 100 full quotation rows (each with its own further per-row line-item
 * query in App\Services\Business\QuotationService::present) just to
 * discard everything but the count would be real, avoidable work for a
 * cap this deployment is never going to hit.
 *
 * Same "no field-level permission check beyond the portal-level gate"
 * precedent as BuyerPortalSnapshotService: the source's own
 * SellerPortalPage calls these repository functions directly after
 * `requirePortalAccess(user, "seller")` passes.
 */
class SellerPortalSnapshotService
{
    public function __construct(
        private readonly OrganisationResolver $organisations,
        private readonly DashboardSnapshotService $dashboard,
        private readonly VatLifecycleService $vatLifecycle,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $actor): array
    {
        $organisation = $this->organisations->resolve($actor, null);

        $quotationCount = (int) DB::table('quotations')->where('organisation_id', $organisation->id)->count();
        $quotedValueCents = (int) DB::table('quotations')
            ->where('organisation_id', $organisation->id)
            ->whereIn('status', ['ISSUED', 'ACCEPTED', 'CONVERTED'])
            ->sum('total_cents');

        return [
            'organisation_id' => $organisation->id,
            'dashboard' => $this->dashboard->snapshot($actor),
            'vat' => $this->vatLifecycle->snapshot($actor),
            'quotations' => ['count' => $quotationCount, 'quoted_value_cents' => $quotedValueCents],
        ];
    }
}

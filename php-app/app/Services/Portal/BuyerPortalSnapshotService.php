<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Platform\PlatformSnapshotService;
use App\Services\VatLifecycle\VatLifecycleService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Ported from the source's own app/portal/buyer/page.tsx -- specifically
 * the subset of lib/data/business-repository.ts's getBusinessPlatformSnapshot
 * this one page actually reads (`expenses`, `metrics.expense_value_cents`),
 * not that function's full 12-query aggregate (parties/products/
 * quotations/accounts/journals/balances/projects/imports/categories/
 * warehouses -- every one of those already has its own dedicated,
 * already-ported controller; porting the whole mega-snapshot here would
 * duplicate all of them for zero UI consumer). `vat`/`documents` reuse
 * App\Services\VatLifecycle\VatLifecycleService::snapshot and
 * App\Services\Platform\PlatformSnapshotService::documentCustodySummary
 * directly -- the exact reads the source's own getVatLifecycleSnapshot/
 * getDocumentCustodySummary calls make, no second query path.
 *
 * Deliberately no `expenses:read`/`returns:read` permission check beyond
 * the portal-level gate `App\Http\Controllers\Portal\BuyerPortalController`
 * already applies: the source's own BuyerPortalPage calls these
 * repository functions directly after `requirePortalAccess(user, "buyer")`
 * passes, with no further per-field permission check -- the same
 * "one page-level gate, not a recombination of every field's own
 * permission" precedent App\Services\Dashboard\DashboardSnapshotService's
 * own doc comment already established for `dashboard:read`.
 */
class BuyerPortalSnapshotService
{
    public function __construct(
        private readonly OrganisationResolver $organisations,
        private readonly VatLifecycleService $vatLifecycle,
        private readonly PlatformSnapshotService $platform,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $actor): array
    {
        $organisation = $this->organisations->resolve($actor, null);

        $expenses = DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.category_id')
            ->leftJoin('business_parties as p', 'p.id', '=', 'e.supplier_party_id')
            ->where('e.organisation_id', $organisation->id)
            ->orderByDesc('e.expense_date')->orderByDesc('e.created_at')->limit(100)
            ->get(['e.*', 'c.name as category_name', 'p.display_name as supplier_name']);

        $expenseValueCents = (int) DB::table('expenses')
            ->where('organisation_id', $organisation->id)->where('status', 'APPROVED')
            ->sum('total_cents');

        return [
            'organisation_id' => $organisation->id,
            'expenses' => $expenses->map(fn ($row) => (array) $row)->values()->all(),
            'metrics' => ['expense_value_cents' => $expenseValueCents],
            'vat' => $this->vatLifecycle->snapshot($actor),
            'documents' => $this->platform->documentCustodySummary($actor),
        ];
    }
}

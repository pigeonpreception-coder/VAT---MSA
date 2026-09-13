<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Project;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\Operations\FixedAssetService;
use App\Services\Operations\LogisticsService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/erp/page.tsx -- Operations >
 * ERP Module (NamRA e-VAT MS master prompt section 16E), read as an
 * integration boundary rather than a seventh domain model: a cross-module
 * resource overview aggregating Human Resources, Fixed Assets, Inventory,
 * Logistics and Projects into one dashboard. This page holds no data of
 * its own -- every count it shows is read straight from the module that
 * owns it, exactly as the source's own ModuleCard tiles do, and each tile
 * links to the real page for its module.
 */
class ErpViewController extends Controller
{
    public function __construct(
        private readonly AdministrationSnapshotService $administration,
        private readonly FixedAssetService $assets,
        private readonly LogisticsService $logistics,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'expenses:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $canReadEmployees = $user->hasAppPermission('employees:read');
        $canReadAssets = $user->hasAppPermission('fixed-assets:read');
        $canReadLogistics = $user->hasAppPermission('logistics:read');

        $activeEmployees = 0;
        if ($canReadEmployees) {
            $snapshot = $this->administration->getAdministrationSnapshot($user, $organisation->id);
            $activeEmployees = collect($snapshot['employees'])->where('status', 'ACTIVE')->count();
        }

        $assets = $canReadAssets ? $this->assets->list($user, null, $organisation->id) : [];
        $activeAssets = collect($assets)->where('status', '!=', 'DISPOSED');
        $assetValueCents = $activeAssets->sum(fn (array $asset) => $asset['current_value_cents'] ?? $asset['acquisition_cost_cents']);

        $deliveries = $canReadLogistics ? $this->logistics->list($user, $organisation->id) : [];
        $pendingDeliveries = collect($deliveries)->where('status', 'PENDING')->count();
        $inTransitDeliveries = collect($deliveries)->where('status', 'IN_TRANSIT')->count();

        $productCount = Product::where('organisation_id', $organisation->id)->count();
        $inventoryValueCents = InventoryBalance::where('organisation_id', $organisation->id)->get()
            ->sum(fn (InventoryBalance $balance) => (int) round(($balance->quantity_micros / 1_000_000) * $balance->average_cost_cents));

        $activeProjects = Project::where('organisation_id', $organisation->id)->whereIn('status', ['PLANNED', 'ACTIVE'])->count();

        return view('operations.erp.index', [
            'canReadEmployees' => $canReadEmployees, 'canReadAssets' => $canReadAssets, 'canReadLogistics' => $canReadLogistics,
            'activeEmployees' => $activeEmployees, 'assetCount' => $activeAssets->count(), 'assetValueCents' => $assetValueCents,
            'productCount' => $productCount, 'inventoryValueCents' => $inventoryValueCents,
            'pendingDeliveries' => $pendingDeliveries, 'inTransitDeliveries' => $inTransitDeliveries,
            'activeProjects' => $activeProjects,
        ]);
    }
}

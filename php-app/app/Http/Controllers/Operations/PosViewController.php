<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\InvoiceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Taxpayer;
use App\Models\Warehouse;
use App\Services\Operations\PosService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/inventory/{page.tsx,
 * PosTerminal.tsx} -- Operations > Inventory Module, "functioning like a
 * Point-of-Sale System" (NamRA e-VAT MS master prompt section 16E). Reuses
 * App\Services\Business\InventoryService's own products/warehouses/
 * balances reads (the same data App\Http\Controllers\Business\
 * InventoryController and OperationsViewController already serve) rather
 * than a second query path, and App\Services\Operations\PosService for the
 * one write this page adds: checkout.
 */
class PosViewController extends Controller
{
    public function __construct(
        private readonly PosService $pos,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'inventory:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $products = Product::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();
        $warehouses = Warehouse::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();
        $balances = InventoryBalance::where('organisation_id', $organisation->id)->get(['warehouse_id', 'product_id', 'quantity_micros']);
        $taxpayers = Taxpayer::orderBy('legal_name')->limit(200)->get(['id', 'legal_name', 'vat_number']);

        return view('operations.pos.index', [
            'products' => $products, 'warehouses' => $warehouses, 'balances' => $balances, 'taxpayers' => $taxpayers,
            'canSell' => $user->hasAppPermission('inventory:manage'),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'inventory:manage');
        $productIds = (array) $request->input('product_id', []);
        $quantities = (array) $request->input('quantity', []);
        $cart = [];
        foreach ($productIds as $index => $productId) {
            $quantity = (int) ($quantities[$index] ?? 0);
            if ($productId !== '' && $productId !== null && $quantity > 0) {
                $cart[] = ['product_id' => $productId, 'quantity' => $quantity];
            }
        }

        try {
            $result = $this->pos->checkout($cart, (string) $request->input('warehouse_id'), $request->input('customer_vat_number') ?: null, $request->user(), null);
        } catch (BusinessValidationException|InvoiceValidationException $e) {
            return redirect()->route('operations.inventory')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.inventory')->withErrors(['sale' => $e->getMessage()]);
        }

        if (count($result['failures']) > 0) {
            return redirect()->route('invoices.show', ['id' => $result['invoice']['id']])
                ->with('warning', 'Invoice '.$result['invoice']['id'].' was created, but stock could not be adjusted for: '.implode('; ', $result['failures']).'. Reconcile inventory manually.');
        }

        return redirect()->route('invoices.show', ['id' => $result['invoice']['id'], 'created' => 1]);
    }
}

<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\SupplierLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves accounting.supplier-ledger (/accounting/supplier-ledger), a route
 * that was a $plannedRoute stub in routes/web.php until now (removed;
 * route name and accounting:read permission kept identical so the
 * sidebar's Accounting & Finance > Supplier Ledger link needed no change).
 */
class SupplierLedgerViewController extends Controller
{
    public function __construct(private readonly SupplierLedgerService $ledger) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisationId = $request->query('organisation_id');
        $supplierId = $request->query('supplier_id');

        $summary = $this->ledger->summary($request->user(), $organisationId);
        $suppliers = $this->ledger->supplierOptions($request->user(), $organisationId);
        $statement = $supplierId ? $this->ledger->statement($request->user(), $organisationId, $supplierId) : null;

        return view('accounting.supplier-ledger', [
            'summary' => $summary, 'suppliers' => $suppliers, 'statement' => $statement, 'selectedSupplierId' => $supplierId,
        ]);
    }
}

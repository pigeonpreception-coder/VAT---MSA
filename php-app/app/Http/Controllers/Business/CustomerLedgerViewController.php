<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\CustomerLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves accounting.customer-ledger (/accounting/customer-ledger), a route
 * that was a $plannedRoute stub in routes/web.php until now (removed;
 * route name and accounting:read permission kept identical so the
 * sidebar's Accounting & Finance > Customer Ledger link needed no change).
 */
class CustomerLedgerViewController extends Controller
{
    public function __construct(private readonly CustomerLedgerService $ledger) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisationId = $request->query('organisation_id');
        $customerId = $request->query('customer_id');

        $summary = $this->ledger->summary($request->user(), $organisationId);
        $customers = $this->ledger->customerOptions($request->user(), $organisationId);
        $statement = $customerId ? $this->ledger->statement($request->user(), $organisationId, $customerId) : null;

        return view('accounting.customer-ledger', [
            'summary' => $summary, 'customers' => $customers, 'statement' => $statement, 'selectedCustomerId' => $customerId,
        ]);
    }
}

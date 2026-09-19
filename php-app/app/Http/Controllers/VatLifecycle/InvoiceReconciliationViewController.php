<?php

namespace App\Http\Controllers\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Http\Controllers\Controller;
use App\Services\VatLifecycle\VatReconciliationReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for the Invoice Reconciliation Report: a VAT period's
 * certified-invoice totals broken down by tax category (mirroring the
 * accountant working-paper format NamRA taxpayers already use), reconciled
 * against the taxpayer's own filed VatReturnVersion for the same period.
 * Was previously a $plannedRoute stub in routes/web.php -- the route name
 * (vat-management.reconciliation) and permission (compliance:read) are kept
 * identical so the sidebar link under VAT Management > Invoice
 * Reconciliation needed no change.
 */
class InvoiceReconciliationViewController extends Controller
{
    public function __construct(private readonly VatReconciliationReportService $reports) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();
        $periods = $this->reports->periodOptions($actor);
        $periodId = $request->query('period_id');

        try {
            $report = ($periodId === null && $periods->isEmpty()) ? null : $this->reports->invoiceReconciliation($actor, $periodId);
        } catch (VatLifecycleResourceException) {
            $report = null;
        }

        return view('vat-management.reconciliation', [
            'periods' => $periods,
            'report' => $report,
            'selectedPeriodId' => $report['period']['id'] ?? $periodId,
        ]);
    }
}

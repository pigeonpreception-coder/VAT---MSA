<?php

namespace App\Http\Controllers\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Http\Controllers\Controller;
use App\Services\VatLifecycle\VatAdjustmentReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for the VAT Adjustment Report: a period's credit/debit note
 * corrections (audit-trail-only -- already reflected in output/input VAT via
 * their own ledger entries) shown alongside the period's manual VatAdjustment
 * records, which are reconciled against the taxpayer's filed VatReturnVersion.
 * Was previously a $plannedRoute stub in routes/web.php -- the route name
 * (vat-management.adjustment-report) and permission (compliance:read) are
 * kept identical so the sidebar link under VAT Management > VAT Adjustment
 * Report needed no change.
 */
class VatAdjustmentReportViewController extends Controller
{
    public function __construct(private readonly VatAdjustmentReportService $reports) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();
        $periods = $this->reports->periodOptions($actor);
        $periodId = $request->query('period_id');

        try {
            $report = ($periodId === null && $periods->isEmpty()) ? null : $this->reports->report($actor, $periodId);
        } catch (VatLifecycleResourceException) {
            $report = null;
        }

        return view('vat-management.adjustment-report', [
            'periods' => $periods,
            'report' => $report,
            'selectedPeriodId' => $report['period']['id'] ?? $periodId,
        ]);
    }
}

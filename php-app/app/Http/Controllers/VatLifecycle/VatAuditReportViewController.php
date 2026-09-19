<?php

namespace App\Http\Controllers\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Http\Controllers\Controller;
use App\Services\VatLifecycle\VatAuditReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for the VAT Audit Report: a period's certified-invoice risk
 * posture, drawn from certified invoices (grouped by risk level), the
 * reconciliation exceptions they raised, and any audit case opened against
 * the taxpayer during the period. Was previously a $plannedRoute stub in
 * routes/web.php -- the route name (vat-management.audit-report) and
 * permission (compliance:read) are kept identical so the sidebar link under
 * VAT Management > VAT Audit Report needed no change.
 */
class VatAuditReportViewController extends Controller
{
    public function __construct(private readonly VatAuditReportService $reports) {}

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

        return view('vat-management.audit-report', [
            'periods' => $periods,
            'report' => $report,
            'selectedPeriodId' => $report['period']['id'] ?? $periodId,
        ]);
    }
}

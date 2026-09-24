<?php

namespace App\Services\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Models\AuditCase;
use App\Models\Invoice;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Models\VatPeriod;
use App\Support\Access\TaxpayerScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only reporting service backing the VAT Audit Report -- a period's
 * certified-invoice risk posture, drawn from the same three data sources
 * the $plannedRoute placeholder this replaces named as the "closest
 * existing equivalent" (Risk Indicators, Audit Cases, Compliance
 * Overview), rather than the report format those three pages already
 * cover individually: certified output invoices grouped by risk level
 * (`invoices.risk_level`, set by `InvoiceCalculator::score()` at
 * certification time), the reconciliation exceptions those same
 * certifications raised (`reconciliation_exceptions`, written by
 * `InvoiceService::submit()`'s own risk-flagging block), and any audit
 * case NamRA opened against the taxpayer during the period
 * (`audit_cases`). `reconciliation_matches` was deliberately left out --
 * confirmed by reading `VatLifecycleService::snapshot()`'s own usage and
 * the table's migration doc comment that no application code anywhere
 * ever writes to it, so including it here would show a permanently-empty
 * section that looks like a bug rather than real, populated evidence.
 */
class VatAuditReportService
{
    private const RISK_LEVELS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** @return Collection<int, VatPeriod> periods the actor may pick for the report, most recent first. */
    public function periodOptions(User $actor): Collection
    {
        $scoped = ! TaxpayerScope::isNational($actor);

        return VatPeriod::with('taxpayer')
            ->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('period_end')->limit(200)->get();
    }

    /** @return array<string, mixed> */
    public function report(User $actor, ?string $periodId): array
    {
        $period = $this->resolvePeriod($actor, $periodId);

        return [
            'period' => $this->presentPeriod($period),
            'invoice_summary' => $this->invoiceSummary($period),
            'exceptions' => $this->exceptions($period),
            'audit_cases' => $this->auditCases($period),
        ];
    }

    private function resolvePeriod(User $actor, ?string $periodId): VatPeriod
    {
        $scoped = ! TaxpayerScope::isNational($actor);
        $period = $periodId
            ? VatPeriod::with('taxpayer')->find($periodId)
            : VatPeriod::with('taxpayer')->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
                ->orderByDesc('period_end')->first();
        if (! $period) {
            throw new VatLifecycleResourceException('No VAT period is available to report on.', 404);
        }
        TaxpayerScope::requireTaxpayer($actor, $period->taxpayer_id);

        return $period;
    }

    /**
     * @return array{by_risk_level: list<array{risk_level: string, count: int, total_cents: int}>, by_status: list<array{status: string, count: int}>, total_count: int, total_cents: int}
     */
    private function invoiceSummary(VatPeriod $period): array
    {
        $invoices = Invoice::where('supplier_taxpayer_id', $period->taxpayer_id)
            ->whereBetween('issue_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->get(['risk_level', 'status', 'total_cents']);

        $byRisk = [];
        foreach (self::RISK_LEVELS as $level) {
            $byRisk[$level] = ['risk_level' => $level, 'count' => 0, 'total_cents' => 0];
        }
        $byStatus = [];
        $totalCents = 0;
        foreach ($invoices as $invoice) {
            if (isset($byRisk[$invoice->risk_level])) {
                $byRisk[$invoice->risk_level]['count']++;
                $byRisk[$invoice->risk_level]['total_cents'] += (int) $invoice->total_cents;
            }
            $byStatus[$invoice->status] ??= ['status' => $invoice->status, 'count' => 0];
            $byStatus[$invoice->status]['count']++;
            $totalCents += (int) $invoice->total_cents;
        }

        return [
            'by_risk_level' => array_values($byRisk),
            'by_status' => array_values($byStatus),
            'total_count' => $invoices->count(),
            'total_cents' => $totalCents,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function exceptions(VatPeriod $period): array
    {
        return ReconciliationException::query()
            ->join('invoices', 'invoices.id', '=', 'reconciliation_exceptions.invoice_id')
            ->where('reconciliation_exceptions.taxpayer_id', $period->taxpayer_id)
            ->whereBetween('reconciliation_exceptions.created_at', [$period->period_start->startOfDay(), $period->period_end->endOfDay()])
            ->orderByDesc('reconciliation_exceptions.created_at')
            ->select(['reconciliation_exceptions.*', 'invoices.invoice_number'])
            ->get()
            ->map(fn ($row) => [
                'invoice_number' => $row->invoice_number, 'exception_type' => $row->exception_type,
                'severity' => $row->severity, 'status' => $row->status, 'summary' => $row->summary,
                'created_at' => optional($row->created_at)->toDateString(), 'resolved_at' => optional($row->resolved_at)->toDateString(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function auditCases(VatPeriod $period): array
    {
        return AuditCase::where('taxpayer_id', $period->taxpayer_id)
            ->whereBetween('opened_at', [$period->period_start->startOfDay(), $period->period_end->endOfDay()])
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (AuditCase $case) => [
                'case_number' => $case->case_number, 'case_type' => $case->case_type, 'title' => $case->title,
                'risk_tier' => $case->risk_tier, 'status' => $case->status,
                'opened_at' => optional($case->opened_at)->toDateString(), 'closed_at' => optional($case->closed_at)->toDateString(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function presentPeriod(VatPeriod $period): array
    {
        return [
            'id' => $period->id, 'period_code' => $period->period_code,
            'period_start' => optional($period->period_start)->toDateString(),
            'period_end' => optional($period->period_end)->toDateString(),
            'status' => $period->status, 'taxpayer_id' => $period->taxpayer_id,
            'legal_name' => $period->taxpayer?->legal_name, 'vat_number' => $period->taxpayer?->vat_number,
        ];
    }
}

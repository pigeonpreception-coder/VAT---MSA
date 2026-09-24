<?php

namespace App\Services\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Models\InvoiceCorrection;
use App\Models\User;
use App\Models\VatAdjustment;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use App\Support\Access\TaxpayerScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only reporting service backing the VAT Adjustment Report -- a period's
 * two genuinely distinct adjustment mechanisms, shown side by side rather
 * than conflated:
 *
 * 1. Credit/debit note corrections (`invoice_corrections`, linking a
 *    CREDIT_NOTE/DEBIT_NOTE invoice back to the original it corrects).
 *    These already flow through the normal OUTPUT_VAT/INPUT_VAT ledger
 *    entries at invoice-certification time (see
 *    `InvoiceService::submit()`'s own `$reversesVat`/ledger-entry block) --
 *    a credit note's monetary fields are already negative and a debit
 *    note's already positive (enforced by `InvoiceCalculator`), so summing
 *    `tax_cents`/`net_amount_cents` across corrections needs no extra sign
 *    handling. Shown here purely as an audit trail of *why* a period's
 *    output/input VAT moved, not as a separate figure to reconcile.
 * 2. `vat_adjustments` -- manual, maker-checker-approved period-level
 *    adjustments (`VatLifecycleService::createAdjustment()`/
 *    `decideApproval()`), which are NOT invoice-driven and feed directly
 *    into a filed `VatReturnVersion`'s own `adjustment_cents`/`BOX_ADJUST`
 *    (see `VatLifecycleService::generateReturn()`). This is the one half
 *    of the report that has a real figure to reconcile against the filed
 *    return, and the only one this service computes a discrepancy for.
 */
class VatAdjustmentReportService
{
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

        $outputCorrections = $this->corrections($period, 'OUTPUT');
        $inputCorrections = $this->corrections($period, 'INPUT');
        $periodAdjustments = $this->periodAdjustments($period);
        $filed = VatReturnVersion::where('vat_period_id', $period->id)->orderByDesc('version_number')->first();

        return [
            'period' => $this->presentPeriod($period),
            'output_corrections' => $outputCorrections,
            'input_corrections' => $inputCorrections,
            'period_adjustments' => $periodAdjustments,
            'filed_return' => $filed ? $this->presentFiled($filed) : null,
            'discrepancy_cents' => $filed ? $periodAdjustments['approved_net_cents'] - (int) $filed->adjustment_cents : null,
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
     * @return array{items: list<array<string, mixed>>, credit_note_count: int, debit_note_count: int, net_taxable_cents: int, net_vat_cents: int, net_total_cents: int}
     */
    private function corrections(VatPeriod $period, string $direction): array
    {
        $partyColumn = $direction === 'OUTPUT' ? 'invoices.supplier_taxpayer_id' : 'invoices.customer_taxpayer_id';

        $rows = InvoiceCorrection::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_corrections.correction_invoice_id')
            ->join('invoices as originals', 'originals.id', '=', 'invoice_corrections.original_invoice_id')
            ->where($partyColumn, $period->taxpayer_id)
            ->whereBetween('invoices.issue_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->orderBy('invoices.issue_date')
            ->select([
                'invoice_corrections.correction_type', 'invoice_corrections.reason_code', 'invoice_corrections.reason', 'invoice_corrections.status',
                'invoices.issue_date', 'invoices.invoice_number as correction_invoice_number', 'invoices.line_net_cents', 'invoices.tax_cents', 'invoices.total_cents',
                'invoices.supplier_name', 'invoices.customer_name', 'originals.invoice_number as original_invoice_number',
            ])->get();

        $items = [];
        $netTaxable = 0;
        $netVat = 0;
        $netTotal = 0;
        $creditCount = 0;
        $debitCount = 0;
        foreach ($rows as $row) {
            $items[] = [
                'date' => optional($row->issue_date)->toDateString(),
                'correction_type' => $row->correction_type,
                'original_invoice_number' => $row->original_invoice_number,
                'correction_invoice_number' => $row->correction_invoice_number,
                'party_name' => $direction === 'OUTPUT' ? $row->customer_name : $row->supplier_name,
                'reason_code' => $row->reason_code,
                'reason' => $row->reason,
                'status' => $row->status,
                'taxable_cents' => (int) $row->line_net_cents,
                'vat_cents' => (int) $row->tax_cents,
                'total_cents' => (int) $row->total_cents,
            ];
            $netTaxable += (int) $row->line_net_cents;
            $netVat += (int) $row->tax_cents;
            $netTotal += (int) $row->total_cents;
            $row->correction_type === 'CREDIT_NOTE' ? $creditCount++ : $debitCount++;
        }

        return [
            'items' => $items, 'credit_note_count' => $creditCount, 'debit_note_count' => $debitCount,
            'net_taxable_cents' => $netTaxable, 'net_vat_cents' => $netVat, 'net_total_cents' => $netTotal,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, approved_net_cents: int, pending_count: int} */
    private function periodAdjustments(VatPeriod $period): array
    {
        $rows = VatAdjustment::where('vat_period_id', $period->id)->orderBy('created_at')->get();

        $items = [];
        $approvedNet = 0;
        $pendingCount = 0;
        foreach ($rows as $row) {
            $signedCents = $row->direction === 'INCREASE' ? (int) $row->amount_cents : -(int) $row->amount_cents;
            $items[] = [
                'id' => $row->id, 'adjustment_type' => $row->adjustment_type, 'direction' => $row->direction,
                'amount_cents' => (int) $row->amount_cents, 'signed_cents' => $signedCents,
                'reason_code' => $row->reason_code, 'explanation' => $row->explanation, 'status' => $row->status,
                'created_at' => optional($row->created_at)->toDateString(), 'approved_at' => optional($row->approved_at)->toDateString(),
            ];
            if ($row->status === 'APPROVED') {
                $approvedNet += $signedCents;
            } elseif ($row->status === 'PENDING_APPROVAL') {
                $pendingCount++;
            }
        }

        return ['items' => $items, 'approved_net_cents' => $approvedNet, 'pending_count' => $pendingCount];
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

    /** @return array<string, mixed> */
    private function presentFiled(VatReturnVersion $version): array
    {
        return [
            'id' => $version->id, 'version_number' => $version->version_number, 'status' => $version->status,
            'adjustment_cents' => (int) $version->adjustment_cents, 'net_payable_cents' => (int) $version->net_payable_cents,
        ];
    }
}

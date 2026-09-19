<?php

namespace App\Services\VatLifecycle;

use App\Exceptions\VatLifecycleResourceException;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use App\Support\Access\TenantScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only reporting service backing two taxpayer-facing period reports:
 * the Invoice Reconciliation Report (certified-invoice totals by VAT
 * category, cross-checked against the taxpayer's filed VatReturnVersion)
 * and the NamRA VAT Summary Report (the filed return rendered in the same
 * category-by-category shape NamRA's own VAT Summary document uses). Both
 * derive their category breakdown from invoice_lines.tax_category via the
 * same private helper, and that helper filters invoice status exactly the
 * way VatLifecycleService::generateReturn() does (OUTPUT: CERTIFIED,
 * MATCHED, EXCEPTION; INPUT: MATCHED only) -- so a period with no drift
 * since it was filed reproduces the filed figures exactly, and any
 * difference is a genuine signal, not a modelling artefact.
 */
class VatReconciliationReportService
{
    private const OUTPUT_STATUSES = ['CERTIFIED', 'MATCHED', 'EXCEPTION'];

    private const INPUT_STATUSES = ['MATCHED'];

    private const CATEGORY_ORDER = ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUTSIDE_SCOPE', 'REVERSE_CHARGE', 'OTHER'];

    private const CATEGORY_LABELS = [
        'STANDARD' => 'Standard-Rated VAT', 'ZERO_RATED' => 'Zero-Rated VAT', 'EXEMPT' => 'Exempt VAT',
        'OUTSIDE_SCOPE' => 'Outside-Scope', 'REVERSE_CHARGE' => 'Reverse-Charge', 'OTHER' => 'Other',
    ];

    /** @return Collection<int, VatPeriod> periods the actor may pick for either report, most recent first. */
    public function periodOptions(User $actor): Collection
    {
        $scoped = ! TenantScope::isNational($actor);

        return VatPeriod::with('taxpayer')
            ->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('period_end')->limit(200)->get();
    }

    /** @return array<string, mixed> */
    public function invoiceReconciliation(User $actor, ?string $periodId): array
    {
        $period = $this->resolvePeriod($actor, $periodId);
        $output = $this->categoryBreakdown($period->taxpayer_id, 'OUTPUT', $period);
        $input = $this->categoryBreakdown($period->taxpayer_id, 'INPUT', $period);
        $computedNetPayableCents = $output['total_vat_cents'] - $input['total_vat_cents'];
        $filed = VatReturnVersion::where('vat_period_id', $period->id)->orderByDesc('version_number')->first();

        return [
            'period' => $this->presentPeriod($period),
            'output' => $output,
            'input' => $input,
            'computed_net_payable_cents' => $computedNetPayableCents,
            'filed_return' => $filed ? $this->presentFiled($filed) : null,
            'discrepancy_cents' => $filed ? $computedNetPayableCents - (int) $filed->net_payable_cents : null,
        ];
    }

    /** @return array<string, mixed> */
    public function namraSummary(User $actor, ?string $periodId): array
    {
        $period = $this->resolvePeriod($actor, $periodId);
        $output = $this->categoryBreakdown($period->taxpayer_id, 'OUTPUT', $period);
        $input = $this->categoryBreakdown($period->taxpayer_id, 'INPUT', $period);
        $filed = VatReturnVersion::where('vat_period_id', $period->id)->orderByDesc('version_number')->first();

        return [
            'period' => $this->presentPeriod($period),
            'output' => $output,
            'input' => $input,
            'filed_return' => $filed ? $this->presentFiled($filed) : null,
        ];
    }

    private function resolvePeriod(User $actor, ?string $periodId): VatPeriod
    {
        $scoped = ! TenantScope::isNational($actor);
        $period = $periodId
            ? VatPeriod::with('taxpayer')->find($periodId)
            : VatPeriod::with('taxpayer')->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
                ->orderByDesc('period_end')->first();
        if (! $period) {
            throw new VatLifecycleResourceException('No VAT period is available to report on.', 404);
        }
        TenantScope::requireTaxpayer($actor, $period->taxpayer_id);

        return $period;
    }

    /**
     * @return array{categories: list<array<string, mixed>>, total_taxable_cents: int, total_vat_cents: int, total_inclusive_cents: int}
     */
    private function categoryBreakdown(string $taxpayerId, string $direction, VatPeriod $period): array
    {
        $partyColumn = $direction === 'OUTPUT' ? 'invoices.supplier_taxpayer_id' : 'invoices.customer_taxpayer_id';
        $statuses = $direction === 'OUTPUT' ? self::OUTPUT_STATUSES : self::INPUT_STATUSES;

        $rows = InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where($partyColumn, $taxpayerId)
            ->whereBetween('invoices.issue_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->whereIn('invoices.status', $statuses)
            ->orderBy('invoices.issue_date')->orderBy('invoice_lines.line_number')
            ->select([
                'invoice_lines.description', 'invoice_lines.net_amount_cents', 'invoice_lines.tax_amount_cents', 'invoice_lines.tax_category',
                'invoices.invoice_number', 'invoices.issue_date', 'invoices.supplier_name', 'invoices.customer_name',
            ])->get();

        $byCategory = [];
        $totalTaxable = 0;
        $totalVat = 0;
        foreach ($rows as $row) {
            $category = $row->tax_category;
            if (! isset($byCategory[$category])) {
                $byCategory[$category] = ['lines' => [], 'taxable_cents' => 0, 'vat_cents' => 0];
            }
            $line = [
                'date' => optional($row->issue_date)->toDateString(),
                'invoice_number' => $row->invoice_number,
                'party_name' => $direction === 'OUTPUT' ? $row->customer_name : $row->supplier_name,
                'description' => $row->description,
                'taxable_cents' => (int) $row->net_amount_cents,
                'vat_cents' => (int) $row->tax_amount_cents,
            ];
            $byCategory[$category]['lines'][] = $line;
            $byCategory[$category]['taxable_cents'] += $line['taxable_cents'];
            $byCategory[$category]['vat_cents'] += $line['vat_cents'];
            $totalTaxable += $line['taxable_cents'];
            $totalVat += $line['vat_cents'];
        }

        $categories = [];
        foreach (self::CATEGORY_ORDER as $category) {
            if (! isset($byCategory[$category])) {
                continue;
            }
            $categories[] = [
                'category' => $category,
                'label' => self::CATEGORY_LABELS[$category],
                'lines' => $byCategory[$category]['lines'],
                'taxable_cents' => $byCategory[$category]['taxable_cents'],
                'vat_cents' => $byCategory[$category]['vat_cents'],
                'total_cents' => $byCategory[$category]['taxable_cents'] + $byCategory[$category]['vat_cents'],
            ];
        }

        return [
            'categories' => $categories,
            'total_taxable_cents' => $totalTaxable,
            'total_vat_cents' => $totalVat,
            'total_inclusive_cents' => $totalTaxable + $totalVat,
        ];
    }

    /** @return array<string, mixed> */
    private function presentPeriod(VatPeriod $period): array
    {
        return [
            'id' => $period->id, 'period_code' => $period->period_code,
            'period_start' => optional($period->period_start)->toDateString(),
            'period_end' => optional($period->period_end)->toDateString(),
            'due_date' => optional($period->due_date)->toDateString(), 'status' => $period->status,
            'taxpayer_id' => $period->taxpayer_id, 'legal_name' => $period->taxpayer?->legal_name,
            'vat_number' => $period->taxpayer?->vat_number, 'tin' => $period->taxpayer?->tin,
            'address' => $period->taxpayer?->address,
        ];
    }

    /** @return array<string, mixed> */
    private function presentFiled(VatReturnVersion $version): array
    {
        return [
            'id' => $version->id, 'version_number' => $version->version_number, 'status' => $version->status,
            'output_tax_cents' => (int) $version->output_tax_cents, 'input_tax_cents' => (int) $version->input_tax_cents,
            'adjustment_cents' => (int) $version->adjustment_cents, 'net_payable_cents' => (int) $version->net_payable_cents,
            'generated_at' => optional($version->generated_at)->toISOString(),
        ];
    }
}

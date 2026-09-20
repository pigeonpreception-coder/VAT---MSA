<?php

namespace App\Http\Controllers\Invoice;

use App\Domain\Invoice\InvoiceCalculator;
use App\Exceptions\InvoiceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Serves new-registration.credit-note/.debit-note, two $plannedRoute stubs
 * in routes/web.php until now (removed; route names and invoices:read
 * permission kept identical so the sidebar's New Registration group needed
 * no change). Unlike every other placeholder closed this session, these
 * two carried their own explicit change-control note ("an unapproved form
 * must be proposed before it is built") rather than a stale-or-missing-
 * model excuse -- the form design below was proposed and approved before
 * any of this was written; see docs/MIGRATION_MATRIX.md for the record.
 *
 * The write path itself is not new: a credit/debit note is just an
 * App\Services\Invoice\InvoiceService::submit() call with document_type
 * CREDIT_NOTE/DEBIT_NOTE and an original_document_reference, already fully
 * built, already reachable at POST /api/v1/invoices, already exercised by
 * tests/Feature/Invoice/InvoiceLifecycleTest.php. This controller's only
 * job is building that same payload from a Blade form instead of a raw
 * JSON body -- never a second write path -- and, for a credit note,
 * showing the original invoice's own lines so their quantity/unit
 * price/tax rate/category never have to be retyped (retyping them is how a
 * credit note could accidentally not match the original and get rejected,
 * or worse, use the wrong VAT rate).
 *
 * Supplier and customer are never asked for either: both are carried over
 * verbatim from the original invoice's own stored supplier_name/
 * supplier_vat_number/customer_name/customer_vat_number, because
 * InvoiceService::resolveOriginalInvoice() requires the correction to
 * preserve the original's exact customer identity -- letting the user
 * retype it is the one way this would fail with a confusing error rather
 * than never being wrong in the first place. One real, disclosed limit
 * from that: if the original's customer was identified by TIN or another
 * non-VAT identifier (not stored on the invoices table, only
 * customer_vat_number is), it cannot be reconstructed here and that
 * invoice is excluded from the eligible list -- see eligibleOriginals().
 */
class InvoiceCorrectionViewController extends Controller
{
    private const ORIGINAL_DOCUMENT_TYPES = ['TAX_INVOICE', 'SIMPLIFIED_TAX_INVOICE', 'SELF_BILLED_INVOICE'];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceCalculator $calculator,
    ) {}

    public function newCreditNote(Request $request): View
    {
        $this->authorize('permission', 'invoices:read');
        $originals = $this->eligibleOriginals($request->user());
        $selected = $this->selectedOriginal($request, $originals);
        $lines = $selected ? InvoiceLine::where('invoice_id', $selected->id)->orderBy('line_number')->get() : collect();

        return view('invoices.new-credit-note', [
            'originals' => $originals, 'selected' => $selected, 'lines' => $lines,
            'remainingCents' => $selected ? $this->remainingCreditableCents($selected) : null,
        ]);
    }

    public function storeCreditNote(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'invoices:submit');
        $original = $this->selectedOriginal($request, $this->eligibleOriginals($request->user()), (string) $request->input('invoice_id'));
        if (! $original) {
            return redirect()->route('new-registration.credit-note')->withErrors(['invoice' => 'Select a valid original invoice.']);
        }
        $back = fn (array $errors) => redirect()->route('new-registration.credit-note', ['invoice_id' => $original->id])->withErrors($errors)->withInput();

        $originalLines = InvoiceLine::where('invoice_id', $original->id)->orderBy('line_number')->get();
        $creditQuantities = (array) $request->input('credit_quantity', []);
        $lines = [];
        foreach ($originalLines as $originalLine) {
            $raw = trim((string) ($creditQuantities[$originalLine->id] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            try {
                $quantityMicros = $this->calculator->decimalToScaled($raw, 6);
                $originalQuantityMicros = $this->calculator->decimalToScaled((string) $originalLine->quantity, 6);
            } catch (\Throwable) {
                return $back(['credit_quantity' => "Line {$originalLine->line_number}'s credit quantity must be a valid decimal amount."]);
            }
            if ($quantityMicros <= 0) {
                continue;
            }
            if ($quantityMicros > $originalQuantityMicros) {
                return $back(['credit_quantity' => "Line {$originalLine->line_number}'s credit quantity cannot exceed the original quantity of {$originalLine->quantity}."]);
            }

            $unitPriceCents = -1 * (int) $originalLine->unit_price_cents;
            $netAmountCents = $this->roundedDivide($quantityMicros * $unitPriceCents, 1_000_000);
            $taxAmountCents = $this->roundedDivide($netAmountCents * (int) $originalLine->tax_rate_bps, 10_000);

            $lines[] = [
                'line_number' => count($lines) + 1, 'description' => $originalLine->description, 'quantity' => $raw, 'unit_code' => $originalLine->unit_code,
                'unit_price' => $this->calculator->centsToDecimal($unitPriceCents), 'net_amount' => $this->calculator->centsToDecimal($netAmountCents),
                'tax' => [
                    'category' => $originalLine->tax_category, 'rate' => $this->calculator->centsToDecimal((int) $originalLine->tax_rate_bps),
                    'taxable_amount' => $this->calculator->centsToDecimal($netAmountCents), 'tax_amount' => $this->calculator->centsToDecimal($taxAmountCents),
                ],
            ];
        }
        if (empty($lines)) {
            return $back(['credit_quantity' => 'Enter a quantity to credit for at least one line.']);
        }

        return $this->submitCorrection($request, $original, 'CREDIT_NOTE', $lines, 'new-registration.credit-note');
    }

    public function newDebitNote(Request $request): View
    {
        $this->authorize('permission', 'invoices:read');
        $originals = $this->eligibleOriginals($request->user());

        return view('invoices.new-debit-note', ['originals' => $originals, 'selected' => $this->selectedOriginal($request, $originals)]);
    }

    public function storeDebitNote(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'invoices:submit');
        $original = $this->selectedOriginal($request, $this->eligibleOriginals($request->user()), (string) $request->input('invoice_id'));
        if (! $original) {
            return redirect()->route('new-registration.debit-note')->withErrors(['invoice' => 'Select a valid original invoice.']);
        }
        $back = fn (array $errors) => redirect()->route('new-registration.debit-note', ['invoice_id' => $original->id])->withErrors($errors)->withInput();

        $rawQuantity = trim((string) $request->input('quantity'));
        $unitPriceCents = $this->safeIntegerInput($request->input('unit_price_cents'));
        try {
            $quantityMicros = $this->calculator->decimalToScaled($rawQuantity, 6);
        } catch (\Throwable) {
            return $back(['quantity' => 'Quantity must be a valid decimal amount.']);
        }
        if ($quantityMicros <= 0 || $unitPriceCents === false || $unitPriceCents <= 0) {
            return $back(['quantity' => 'Enter a positive quantity and unit price.']);
        }

        $netAmountCents = $this->roundedDivide($quantityMicros * $unitPriceCents, 1_000_000);
        // Standard Namibia pilot VAT rate (15%), the same hardcoded convention
        // QuotationViewController::createPayload() already uses for its own
        // single-line create form -- a debit note is a genuine new charge, not
        // tied to the original's own lines, so there is no "original tax rate"
        // to carry over the way the credit note above does.
        $taxAmountCents = $this->roundedDivide($netAmountCents * 1500, 10_000);
        $lines = [[
            'line_number' => 1, 'description' => (string) $request->input('description'), 'quantity' => $rawQuantity,
            'unit_code' => (string) $request->input('unit_code', 'EA'), 'unit_price' => $this->calculator->centsToDecimal($unitPriceCents),
            'net_amount' => $this->calculator->centsToDecimal($netAmountCents),
            'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => $this->calculator->centsToDecimal($netAmountCents), 'tax_amount' => $this->calculator->centsToDecimal($taxAmountCents)],
        ]];

        return $this->submitCorrection($request, $original, 'DEBIT_NOTE', $lines, 'new-registration.debit-note');
    }

    /** @param list<array<string, mixed>> $lines */
    private function submitCorrection(Request $request, Invoice $original, string $documentType, array $lines, string $formRoute): RedirectResponse
    {
        $lineNetCents = array_sum(array_map(fn (array $l) => $this->calculator->decimalToScaled($l['net_amount'], 2), $lines));
        $taxCents = array_sum(array_map(fn (array $l) => $this->calculator->decimalToScaled($l['tax']['tax_amount'], 2), $lines));
        $totalCents = $lineNetCents + $taxCents;
        $back = fn (array $errors) => redirect()->route($formRoute, ['invoice_id' => $original->id])->withErrors($errors)->withInput();

        $payload = [
            'schema_version' => '1.0.0', 'document_type' => $documentType,
            'invoice_number' => ($documentType === 'CREDIT_NOTE' ? 'CN-' : 'DN-').mb_strtoupper(Str::random(8)),
            'source' => ['system_id' => 'VAT-MSA-CORRECTION', 'document_id' => (string) Str::uuid(), 'submitted_at' => now()->toISOString()],
            'supplier' => ['name' => $original->supplier_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $original->supplier_vat_number]]],
            'customer' => ['name' => $original->customer_name, 'identifiers' => $original->customer_vat_number
                ? [['type' => 'VAT_NUMBER', 'value' => $original->customer_vat_number]]
                : [['type' => 'OTHER', 'value' => 'WALK-IN']]],
            'issue_date' => (string) $request->input('issue_date'), 'currency' => $original->currency, 'lines' => $lines,
            'original_document_reference' => [
                'vat_msa_invoice_id' => $original->id, 'source_document_id' => $original->source_document_id,
                'reason_code' => mb_strtoupper(trim((string) $request->input('reason_code'))), 'reason' => trim((string) $request->input('reason')),
            ],
            'totals' => [
                'line_net_amount' => $this->calculator->centsToDecimal($lineNetCents), 'tax_exclusive_amount' => $this->calculator->centsToDecimal($lineNetCents),
                'tax_amount' => $this->calculator->centsToDecimal($taxCents), 'tax_inclusive_amount' => $this->calculator->centsToDecimal($totalCents),
                'payable_amount' => $this->calculator->centsToDecimal($totalCents),
            ],
        ];

        try {
            $invoice = $this->invoices->submit($payload, $request->user(), $this->formIdempotencyKey($request), ['correlation_id' => (string) Str::uuid()]);
        } catch (InvoiceValidationException $e) {
            return $back(collect($e->errors())->pluck('message', 'path')->all());
        } catch (RepositoryConflictException $e) {
            return $back(['correction' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', ['id' => $invoice['id'], 'created' => 1]);
    }

    /**
     * Scoped to the acting user's own taxpayer as supplier (the only party
     * who may issue a correction against their own invoice -- matches
     * InvoiceService::resolveOriginalInvoice()'s own supplier_taxpayer_id
     * scope exactly), excluding a cancelled original and excluding an
     * original whose customer identity cannot be reconstructed here (see
     * this class's own doc comment).
     *
     * @return Collection<int, Invoice>
     */
    private function eligibleOriginals(User $user): Collection
    {
        return Invoice::where('supplier_taxpayer_id', $user->taxpayer_id)
            ->whereIn('document_type', self::ORIGINAL_DOCUMENT_TYPES)->where('status', '!=', 'CANCELLED')
            ->where(fn ($q) => $q->whereNull('customer_taxpayer_id')->orWhereNotNull('customer_vat_number'))
            ->orderByDesc('issue_date')->limit(200)->get();
    }

    /** @param Collection<int, Invoice> $originals */
    private function selectedOriginal(Request $request, Collection $originals, ?string $invoiceId = null): ?Invoice
    {
        $invoiceId ??= $request->query('invoice_id');

        return $invoiceId ? $originals->firstWhere('id', $invoiceId) : null;
    }

    /**
     * Original total minus every prior ACTIVE credit note's own total
     * (already negative) -- the same sum
     * InvoiceService::resolveOriginalInvoice()'s own cumulative-credit-cap
     * check makes, surfaced here only for display so the form doesn't ask
     * for more than can actually be credited; the server re-derives and
     * re-enforces this independently on submit, this is not a second
     * source of truth for the cap itself.
     */
    private function remainingCreditableCents(Invoice $original): int
    {
        $priorCreditedCents = (int) (InvoiceCorrection::where('invoice_corrections.original_invoice_id', $original->id)
            ->where('invoice_corrections.correction_type', 'CREDIT_NOTE')->where('invoice_corrections.status', 'ACTIVE')
            ->join('invoices', 'invoices.id', '=', 'invoice_corrections.correction_invoice_id')
            ->sum('invoices.total_cents'));

        return (int) $original->total_cents + $priorCreditedCents;
    }

    /** Ported verbatim from InvoiceCalculator's own private roundedDivide -- half-up, sign-preserving. */
    private function roundedDivide(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;

        return $sign * intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);
    }
}

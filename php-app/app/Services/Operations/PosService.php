<?php

namespace App\Services\Operations;

use App\Domain\Invoice\InvoiceCalculator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\InvoiceValidationException;
use App\Models\Product;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Business\InventoryService;
use App\Services\Invoice\InvoiceService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Str;

/**
 * Ported from app/operations/inventory/PosTerminal.tsx's completeSale --
 * Operations > Inventory Module ("functioning like a Point-of-Sale
 * System", NamRA e-VAT MS master prompt section 16E). The source builds
 * this client-side as two separate fetch() calls (POST /api/v1/invoices,
 * then one POST /api/v1/inventory/movements per cart line); this port does
 * the same two steps server-side in one request, reusing
 * App\Services\Invoice\InvoiceService::submit (Phase 9's certification
 * pipeline, unchanged) and App\Services\Business\InventoryService::
 * recordMovement (Phase 10's ISSUE-type stock movement, which negates the
 * quantity automatically) rather than inventing a third command path.
 *
 * Matches the source's own partial-failure handling: the invoice is
 * certified first and is never rolled back if a stock movement
 * afterwards fails to record (each movement is a separately idempotent,
 * separately auditable command) -- the checkout result reports the
 * invoice plus a list of any line-level stock failures so the till
 * operator can reconcile inventory manually, exactly as the source's own
 * banner does ("Invoice ... was created, but stock could not be adjusted
 * for: ...").
 */
class PosService
{
    public function __construct(
        private readonly OrganisationResolver $organisations,
        private readonly InvoiceService $invoices,
        private readonly InventoryService $inventory,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /**
     * @param list<array{product_id: string, quantity: int}> $cart
     * @return array{invoice: array<string, mixed>, failures: list<string>}
     */
    public function checkout(array $cart, string $warehouseId, ?string $customerVatNumber, User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $warehouse = Warehouse::where('id', $warehouseId)->where('organisation_id', $organisation->id)->first();
        if (! $warehouse) {
            throw new BusinessResourceException('warehouse_id does not exist in the authorised organisation.', 404);
        }
        if (count($cart) < 1) {
            throw new BusinessValidationException([
                ['code' => 'CART_EMPTY', 'path' => '/lines', 'message' => 'The cart must contain at least one line.'],
            ]);
        }

        $products = [];
        foreach ($cart as $index => $cartLine) {
            $productId = (string) ($cartLine['product_id'] ?? '');
            $quantity = (int) ($cartLine['quantity'] ?? 0);
            if ($quantity < 1) {
                throw new BusinessValidationException([
                    ['code' => 'QUANTITY_INVALID', 'path' => "/lines/{$index}/quantity", 'message' => 'Quantity must be at least 1.'],
                ]);
            }
            $product = Product::where('id', $productId)->where('organisation_id', $organisation->id)->first();
            if (! $product) {
                throw new BusinessResourceException("Product {$productId} does not exist in the authorised organisation.", 404);
            }
            $products[] = ['product' => $product, 'quantity' => $quantity];
        }

        $supplier = $organisation->taxpayer;
        $customer = $customerVatNumber ? Taxpayer::where('vat_number', mb_strtoupper(trim($customerVatNumber)))->first() : null;

        $lines = [];
        $netTotal = 0;
        $taxTotal = 0;
        foreach ($products as $index => $entry) {
            /** @var Product $product */
            $product = $entry['product'];
            $netCents = (int) $product->sales_price_cents * $entry['quantity'];
            $taxCents = (int) round(($netCents * (int) $product->tax_rate_bps) / 10_000);
            $netTotal += $netCents;
            $taxTotal += $taxCents;
            $lines[] = [
                'line_number' => $index + 1, 'item_code' => $product->id, 'description' => $product->name,
                'quantity' => (string) $entry['quantity'], 'unit_code' => $product->unit_code,
                'unit_price' => $this->calculator->centsToDecimal((int) $product->sales_price_cents),
                'net_amount' => $this->calculator->centsToDecimal($netCents),
                'tax' => [
                    'category' => $product->tax_category === 'OUT_OF_SCOPE' ? 'OUTSIDE_SCOPE' : $product->tax_category,
                    'rate' => $this->calculator->centsToDecimal((int) $product->tax_rate_bps),
                    'taxable_amount' => $this->calculator->centsToDecimal($netCents),
                    'tax_amount' => $this->calculator->centsToDecimal($taxCents),
                ],
            ];
        }
        $totalCents = $netTotal + $taxTotal;

        $posReference = 'POS-'.mb_strtoupper(Str::random(8));
        $invoicePayload = [
            'schema_version' => '1.0.0', 'document_type' => $customer ? 'TAX_INVOICE' : 'SIMPLIFIED_TAX_INVOICE',
            'source' => ['system_id' => 'VAT-MSA-POS', 'document_id' => $posReference, 'submitted_at' => now()->toISOString()],
            'supplier' => ['name' => $supplier->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $supplier->vat_number]]],
            'customer' => $customer
                ? ['name' => $customer->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $customer->vat_number]]]
                : ['name' => 'Walk-in customer', 'identifiers' => [['type' => 'OTHER', 'value' => 'CONSUMER']]],
            'invoice_number' => 'POS-'.now()->format('Ymd').'-'.mb_strtoupper(Str::random(6)),
            'issue_date' => now()->toDateString(), 'currency' => 'NAD', 'lines' => $lines,
            'totals' => [
                'line_net_amount' => $this->calculator->centsToDecimal($netTotal), 'tax_exclusive_amount' => $this->calculator->centsToDecimal($netTotal),
                'tax_amount' => $this->calculator->centsToDecimal($taxTotal), 'tax_inclusive_amount' => $this->calculator->centsToDecimal($totalCents),
                'payable_amount' => $this->calculator->centsToDecimal($totalCents),
            ],
        ];

        $correlationId = (string) Str::uuid();
        $invoice = $this->invoices->submit($invoicePayload, $actor, (string) Str::uuid(), ['correlation_id' => $correlationId]);

        $failures = [];
        foreach ($products as $index => $entry) {
            /** @var Product $product */
            $product = $entry['product'];
            $movementPayload = [
                'schema_version' => '1.0.0', 'warehouse_id' => $warehouseId, 'product_id' => $product->id, 'movement_type' => 'ISSUE',
                'quantity_micros' => $entry['quantity'] * 1_000_000, 'unit_cost_cents' => (int) $product->cost_price_cents,
                'reference_type' => 'INVOICE', 'reference_id' => "{$invoice['id']}:".($index + 1), 'reason' => 'POS sale',
                // Deliberately omitted: BusinessValidator::stockMovement's own
                // ISO_PATTERN requires exactly 3-digit milliseconds (or none),
                // matching JavaScript's Date.toISOString() (the source's own
                // format) -- but Carbon's toISOString() emits 6-digit
                // microseconds, which that pattern rejects. Leaving the key
                // out lets the validator default it via its own
                // gmdate('Y-m-d\TH:i:s.000\Z'), already correctly shaped.
            ];
            try {
                $this->inventory->recordMovement($movementPayload, $actor, (string) Str::uuid(), $correlationId, $organisation->id);
            } catch (BusinessValidationException|InvoiceValidationException $e) {
                $failures[] = "{$product->name}: ".collect($e->errors())->pluck('message')->implode(' ');
            } catch (\Throwable $e) {
                $failures[] = "{$product->name}: {$e->getMessage()}";
            }
        }

        return ['invoice' => $invoice, 'failures' => $failures];
    }
}

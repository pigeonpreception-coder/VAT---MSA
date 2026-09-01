<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's recordStockMovement/
 * createProduct/createWarehouse/transferStock/getInventoryAvailability/
 * getInventoryValuation -- Module 5 Phase D, the fourth Phase 10 slice.
 *
 * inventoryBalanceStatement's own source comment documents a real,
 * pre-existing bug this port sidesteps by construction rather than by
 * porting the same fragile pattern: an `INSERT ... ON CONFLICT DO UPDATE`
 * upsert crashed under a negative delta because the database evaluates a
 * CHECK constraint against the `excluded` pseudo-row's raw pre-update
 * values, not the resolved post-update row. This port never attempts that
 * upsert at all -- upsertBalance below always does a plain fetch-then-
 * insert-or-update inside the same transaction, computing the new
 * quantity/average cost in PHP first, exactly as the source's fix does.
 */
class InventoryService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function recordMovement(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $movement = BusinessValidator::stockMovement($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'movement' => $movement]);
        $prior = CommandLedger::prior($actor->id, 'RECORD_STOCK_MOVEMENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findMovementOrFail($prior, $organisation->id);
        }
        $this->requireOwnedWarehouse($movement['warehouse_id'], $organisation->id);
        $this->requireOwnedProduct($movement['product_id'], $organisation->id);
        $balance = InventoryBalance::where('warehouse_id', $movement['warehouse_id'])->where('product_id', $movement['product_id'])->first();
        if ((int) ($balance->quantity_micros ?? 0) + $movement['quantity_micros'] < 0) {
            throw new RepositoryConflictException('The movement would make on-hand inventory negative.');
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($movement, $balance, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            $this->upsertBalance($organisation->id, $movement['warehouse_id'], $movement['product_id'], $movement['quantity_micros'], $movement['unit_cost_cents'], $balance, $now);
            StockMovement::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'warehouse_id' => $movement['warehouse_id'], 'product_id' => $movement['product_id'],
                'movement_type' => $movement['movement_type'], 'quantity_micros' => $movement['quantity_micros'], 'unit_cost_cents' => $movement['unit_cost_cents'],
                'reference_type' => $movement['reference_type'], 'reference_id' => $movement['reference_id'], 'reason' => $movement['reason'],
                'occurred_at' => $movement['occurred_at'], 'actor_id' => $actor->id,
            ]);
            CommandLedger::record($actor->id, 'RECORD_STOCK_MOVEMENT', $idempotencyKey, $requestHash, 'STOCK_MOVEMENT', $id, $now);
            CommandLedger::outbox('STOCK_MOVEMENT', $id, 'StockMovementRecorded', $organisation->id, ['stock_movement_id' => $id, 'organisation_id' => $organisation->id, 'quantity_micros' => $movement['quantity_micros'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'STOCK_MOVEMENT_RECORDED', 'STOCK_MOVEMENT', $id, ['organisationId' => $organisation->id, 'productId' => $movement['product_id'], 'quantityMicros' => $movement['quantity_micros'], 'correlationId' => $correlationId], $now);
        });

        return $this->findMovementOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function createProduct(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $product = BusinessValidator::product($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'product' => $product]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_PRODUCT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentProduct($this->findProductOrFail($prior, $organisation->id));
        }
        $existing = Product::where('organisation_id', $organisation->id)->where('sku', $product['sku'])->first();
        if ($existing) {
            throw new RepositoryConflictException("SKU {$product['sku']} is already in use ({$existing->name}, {$existing->id}).");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($product, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Product::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'sku' => $product['sku'], 'name' => $product['name'],
                'description' => $product['description'], 'unit_code' => $product['unit_code'], 'tax_category' => $product['tax_category'],
                'tax_rate_bps' => $product['tax_rate_bps'], 'sales_price_cents' => $product['sales_price_cents'], 'cost_price_cents' => $product['cost_price_cents'],
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_PRODUCT', $idempotencyKey, $requestHash, 'PRODUCT', $id, $now);
            CommandLedger::outbox('PRODUCT', $id, 'ProductCreated', $organisation->id, ['product_id' => $id, 'organisation_id' => $organisation->id, 'sku' => $product['sku'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PRODUCT_CREATED', 'PRODUCT', $id, ['organisationId' => $organisation->id, 'sku' => $product['sku'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentProduct($this->findProductOrFail($id, $organisation->id));
    }

    /** @return array<string, mixed> */
    public function createWarehouse(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $warehouse = BusinessValidator::warehouse($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'warehouse' => $warehouse]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_WAREHOUSE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentWarehouse($this->findWarehouseOrFail($prior, $organisation->id));
        }
        $this->requireOwnedBranch($warehouse['branch_id'], $organisation->id);
        $existing = Warehouse::where('organisation_id', $organisation->id)->where('code', $warehouse['code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("Warehouse code {$warehouse['code']} is already in use ({$existing->name}, {$existing->id}).");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($warehouse, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Warehouse::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'branch_id' => $warehouse['branch_id'], 'code' => $warehouse['code'],
                'name' => $warehouse['name'], 'address' => $warehouse['address'], 'status' => 'ACTIVE', 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_WAREHOUSE', $idempotencyKey, $requestHash, 'WAREHOUSE', $id, $now);
            CommandLedger::outbox('WAREHOUSE', $id, 'WarehouseCreated', $organisation->id, ['warehouse_id' => $id, 'organisation_id' => $organisation->id, 'code' => $warehouse['code'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'WAREHOUSE_CREATED', 'WAREHOUSE', $id, ['organisationId' => $organisation->id, 'code' => $warehouse['code'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentWarehouse($this->findWarehouseOrFail($id, $organisation->id));
    }

    /**
     * Both legs (TRANSFER_OUT/TRANSFER_IN) post atomically, sharing one
     * transfer id via reference_id. The destination leg's cost is read
     * from the source warehouse's own current average cost -- a transfer
     * moves the same physical stock, so it preserves cost basis rather
     * than letting the caller fabricate a new one.
     *
     * @return array<string, mixed>
     */
    public function transferStock(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $transfer = BusinessValidator::stockTransfer($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'transfer' => $transfer]);
        $prior = CommandLedger::prior($actor->id, 'TRANSFER_STOCK', $idempotencyKey, $requestHash);
        if ($prior) {
            $movements = StockMovement::where('organisation_id', $organisation->id)->where('reference_id', $prior)->orderBy('movement_type')->get();

            return ['id' => $prior, 'movements' => $movements->map(fn (StockMovement $m) => $this->presentMovement($m))->values()->all()];
        }
        $this->requireOwnedWarehouse($transfer['from_warehouse_id'], $organisation->id);
        $this->requireOwnedWarehouse($transfer['to_warehouse_id'], $organisation->id);
        $this->requireOwnedProduct($transfer['product_id'], $organisation->id);
        $sourceBalance = InventoryBalance::where('warehouse_id', $transfer['from_warehouse_id'])->where('product_id', $transfer['product_id'])->first();
        if ((int) ($sourceBalance->quantity_micros ?? 0) - $transfer['quantity_micros'] < 0) {
            throw new RepositoryConflictException('The transfer would make on-hand inventory negative at the source warehouse.');
        }
        $unitCostCents = (int) ($sourceBalance->average_cost_cents ?? 0);
        $destinationBalance = InventoryBalance::where('warehouse_id', $transfer['to_warehouse_id'])->where('product_id', $transfer['product_id'])->first();

        $id = (string) Str::uuid();
        $now = now();
        $outMovementId = (string) Str::uuid();
        $inMovementId = (string) Str::uuid();
        DB::transaction(function () use ($transfer, $sourceBalance, $destinationBalance, $unitCostCents, $organisation, $actor, $id, $outMovementId, $inMovementId, $now, $idempotencyKey, $requestHash, $correlationId) {
            $this->upsertBalance($organisation->id, $transfer['from_warehouse_id'], $transfer['product_id'], -$transfer['quantity_micros'], $unitCostCents, $sourceBalance, $now);
            $this->upsertBalance($organisation->id, $transfer['to_warehouse_id'], $transfer['product_id'], $transfer['quantity_micros'], $unitCostCents, $destinationBalance, $now);
            StockMovement::create([
                'id' => $outMovementId, 'organisation_id' => $organisation->id, 'warehouse_id' => $transfer['from_warehouse_id'], 'product_id' => $transfer['product_id'],
                'movement_type' => 'TRANSFER_OUT', 'quantity_micros' => -$transfer['quantity_micros'], 'unit_cost_cents' => $unitCostCents,
                'reference_type' => 'TRANSFER_OUT', 'reference_id' => $id, 'reason' => $transfer['reason'], 'occurred_at' => $transfer['occurred_at'], 'actor_id' => $actor->id,
            ]);
            StockMovement::create([
                'id' => $inMovementId, 'organisation_id' => $organisation->id, 'warehouse_id' => $transfer['to_warehouse_id'], 'product_id' => $transfer['product_id'],
                'movement_type' => 'TRANSFER_IN', 'quantity_micros' => $transfer['quantity_micros'], 'unit_cost_cents' => $unitCostCents,
                'reference_type' => 'TRANSFER_IN', 'reference_id' => $id, 'reason' => $transfer['reason'], 'occurred_at' => $transfer['occurred_at'], 'actor_id' => $actor->id,
            ]);
            CommandLedger::record($actor->id, 'TRANSFER_STOCK', $idempotencyKey, $requestHash, 'STOCK_TRANSFER', $id, $now);
            CommandLedger::outbox('STOCK_TRANSFER', $id, 'StockTransferred', $organisation->id, ['stock_transfer_id' => $id, 'organisation_id' => $organisation->id, 'product_id' => $transfer['product_id'], 'quantity_micros' => $transfer['quantity_micros'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'STOCK_TRANSFERRED', 'STOCK_TRANSFER', $id, [
                'organisationId' => $organisation->id, 'productId' => $transfer['product_id'], 'fromWarehouseId' => $transfer['from_warehouse_id'],
                'toWarehouseId' => $transfer['to_warehouse_id'], 'quantityMicros' => $transfer['quantity_micros'], 'correlationId' => $correlationId,
            ], $now);
        });

        $movements = StockMovement::where('organisation_id', $organisation->id)->where('reference_id', $id)->orderBy('movement_type')->get();

        return [
            'id' => $id, 'from_warehouse_id' => $transfer['from_warehouse_id'], 'to_warehouse_id' => $transfer['to_warehouse_id'],
            'product_id' => $transfer['product_id'], 'quantity_micros' => $transfer['quantity_micros'], 'unit_cost_cents' => $unitCostCents,
            'movements' => $movements->map(fn (StockMovement $m) => $this->presentMovement($m))->values()->all(),
        ];
    }

    /** An aggregated on-hand-quantity read, per product across every warehouse. @return array<string, mixed> */
    public function availability(User $actor, ?string $requestedOrganisationId, array $params): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $rows = $this->queryBalances($organisation->id, $params);
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row->product_id] ??= ['product_id' => $row->product_id, 'sku' => $row->sku, 'name' => $row->product_name, 'total_quantity_micros' => 0, 'by_warehouse' => []];
            $byProduct[$row->product_id]['total_quantity_micros'] += (int) $row->quantity_micros;
            $byProduct[$row->product_id]['by_warehouse'][] = ['warehouse_id' => $row->warehouse_id, 'code' => $row->warehouse_code, 'name' => $row->warehouse_name, 'quantity_micros' => (int) $row->quantity_micros];
        }

        return ['organisation_id' => $organisation->id, 'products' => array_values($byProduct)];
    }

    /** On-hand quantity valued at each balance's own weighted-average cost. @return array<string, mixed> */
    public function valuation(User $actor, ?string $requestedOrganisationId, array $params): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $rows = $this->queryBalances($organisation->id, $params);
        $byProduct = [];
        $grandTotalValueCents = 0;
        foreach ($rows as $row) {
            $valueCents = (int) round(((int) $row->quantity_micros * (int) $row->average_cost_cents) / 1_000_000);
            $byProduct[$row->product_id] ??= ['product_id' => $row->product_id, 'sku' => $row->sku, 'name' => $row->product_name, 'total_quantity_micros' => 0, 'total_value_cents' => 0, 'by_warehouse' => []];
            $byProduct[$row->product_id]['total_quantity_micros'] += (int) $row->quantity_micros;
            $byProduct[$row->product_id]['total_value_cents'] += $valueCents;
            $byProduct[$row->product_id]['by_warehouse'][] = [
                'warehouse_id' => $row->warehouse_id, 'code' => $row->warehouse_code, 'name' => $row->warehouse_name,
                'quantity_micros' => (int) $row->quantity_micros, 'average_cost_cents' => (int) $row->average_cost_cents, 'value_cents' => $valueCents,
            ];
            $grandTotalValueCents += $valueCents;
        }

        return ['organisation_id' => $organisation->id, 'total_value_cents' => $grandTotalValueCents, 'products' => array_values($byProduct)];
    }

    // -- internals --

    private function queryBalances(string $organisationId, array $params)
    {
        $query = InventoryBalance::query()
            ->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->where('inventory_balances.organisation_id', $organisationId);
        if (! empty($params['product_id'])) {
            $query->where('inventory_balances.product_id', $params['product_id']);
        }
        if (! empty($params['warehouse_id'])) {
            $query->where('inventory_balances.warehouse_id', $params['warehouse_id']);
        }

        return $query->orderBy('products.name')->orderBy('warehouses.name')
            ->select('inventory_balances.product_id', 'products.sku', 'products.name as product_name', 'inventory_balances.warehouse_id', 'warehouses.code as warehouse_code', 'warehouses.name as warehouse_name', 'inventory_balances.quantity_micros', 'inventory_balances.average_cost_cents')
            ->get();
    }

    /**
     * Fetch-then-insert-or-update, computed in PHP first -- see this
     * class's own doc comment for why, ported from the source's
     * inventoryBalanceStatement.
     */
    private function upsertBalance(string $organisationId, string $warehouseId, string $productId, int $quantityDelta, int $unitCostCents, ?InventoryBalance $existing, \DateTimeInterface $now): void
    {
        if (! $existing) {
            InventoryBalance::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'warehouse_id' => $warehouseId, 'product_id' => $productId,
                'quantity_micros' => $quantityDelta, 'average_cost_cents' => $unitCostCents, 'version' => 1, 'updated_at' => $now,
            ]);

            return;
        }
        $newQuantity = (int) $existing->quantity_micros + $quantityDelta;
        $newAverageCostCents = $quantityDelta > 0 && $newQuantity > 0
            ? (int) round(((int) $existing->quantity_micros * (int) $existing->average_cost_cents + $quantityDelta * $unitCostCents) / $newQuantity)
            : (int) $existing->average_cost_cents;
        InventoryBalance::where('warehouse_id', $warehouseId)->where('product_id', $productId)->update([
            'quantity_micros' => $newQuantity, 'average_cost_cents' => $newAverageCostCents, 'version' => DB::raw('version + 1'), 'updated_at' => $now,
        ]);
    }

    private function requireOwnedWarehouse(string $warehouseId, string $organisationId): void
    {
        $exists = Warehouse::where('id', $warehouseId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Warehouse does not exist in the authorised organisation.', 422);
        }
    }

    private function requireOwnedProduct(string $productId, string $organisationId): void
    {
        $exists = Product::where('id', $productId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Product does not exist in the authorised organisation.', 422);
        }
    }

    private function requireOwnedBranch(?string $branchId, string $organisationId): void
    {
        if (! $branchId) {
            return;
        }
        $exists = DB::table('branches')->where('id', $branchId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Branch does not exist in the authorised organisation.', 422);
        }
    }

    private function findMovementOrFail(string $id, string $organisationId): array
    {
        $movement = StockMovement::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $movement) {
            throw new BusinessResourceException('Stock movement was not found in the authorised organisation.', 404);
        }

        return $this->presentMovement($movement);
    }

    private function presentMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id, 'organisation_id' => $movement->organisation_id, 'warehouse_id' => $movement->warehouse_id,
            'product_id' => $movement->product_id, 'movement_type' => $movement->movement_type, 'quantity_micros' => (int) $movement->quantity_micros,
            'unit_cost_cents' => (int) $movement->unit_cost_cents, 'reference_type' => $movement->reference_type, 'reference_id' => $movement->reference_id,
            'reason' => $movement->reason, 'occurred_at' => optional($movement->occurred_at)->toISOString(), 'actor_id' => $movement->actor_id,
        ];
    }

    private function findProductOrFail(string $id, string $organisationId): Product
    {
        $product = Product::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $product) {
            throw new BusinessResourceException('Product was not found in the authorised organisation.', 404);
        }

        return $product;
    }

    private function presentProduct(Product $product): array
    {
        return [
            'id' => $product->id, 'organisation_id' => $product->organisation_id, 'sku' => $product->sku, 'name' => $product->name,
            'description' => $product->description, 'unit_code' => $product->unit_code, 'tax_category' => $product->tax_category,
            'tax_rate_bps' => (int) $product->tax_rate_bps, 'sales_price_cents' => (int) $product->sales_price_cents, 'cost_price_cents' => (int) $product->cost_price_cents,
            'status' => $product->status, 'created_at' => optional($product->created_at)->toISOString(), 'updated_at' => optional($product->updated_at)->toISOString(),
        ];
    }

    private function findWarehouseOrFail(string $id, string $organisationId): Warehouse
    {
        $warehouse = Warehouse::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $warehouse) {
            throw new BusinessResourceException('Warehouse was not found in the authorised organisation.', 404);
        }

        return $warehouse;
    }

    private function presentWarehouse(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id, 'organisation_id' => $warehouse->organisation_id, 'branch_id' => $warehouse->branch_id,
            'code' => $warehouse->code, 'name' => $warehouse->name, 'address' => $warehouse->address, 'status' => $warehouse->status,
            'created_at' => optional($warehouse->created_at)->toISOString(),
        ];
    }
}

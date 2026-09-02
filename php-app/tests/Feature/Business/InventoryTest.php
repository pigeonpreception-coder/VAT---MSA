<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 10 (slice 4): inventory (App\Services\Business\
 * InventoryService, ported from recordStockMovement/createProduct/
 * createWarehouse/transferStock/getInventoryAvailability/
 * getInventoryValuation) -- Module 5 Phase D.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function createProduct(User $owner, string $sku = 'WIDGET'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/products', [
            'schema_version' => '1.0.0', 'sku' => $sku, 'name' => "Product {$sku}", 'unit_code' => 'EA',
            'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500, 'sales_price_cents' => 20000, 'cost_price_cents' => 10000,
        ], ['Idempotency-Key' => 'test-idem-prod-'.$sku]);

        return $response->json('resource.id');
    }

    private function createWarehouse(User $owner, string $code = 'MAIN'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/warehouses', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Warehouse {$code}", 'address' => '1 Storage Road, Windhoek',
        ], ['Idempotency-Key' => 'test-idem-wh-'.$code]);

        return $response->json('resource.id');
    }

    public function test_a_product_and_warehouse_can_be_created_with_duplicate_codes_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0001');

        $product = $this->actingAs($org['owner'])->postJson('/api/v1/products', [
            'schema_version' => '1.0.0', 'sku' => 'WIDGET', 'name' => 'Widget', 'unit_code' => 'EA',
            'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500, 'sales_price_cents' => 20000, 'cost_price_cents' => 10000,
        ], ['Idempotency-Key' => 'test-idem-prod-widget-0001']);
        $product->assertStatus(201)->assertJsonPath('resource.sku', 'WIDGET');

        $dupProduct = $this->actingAs($org['owner'])->postJson('/api/v1/products', [
            'schema_version' => '1.0.0', 'sku' => 'WIDGET', 'name' => 'Widget 2', 'unit_code' => 'EA',
            'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500, 'sales_price_cents' => 20000, 'cost_price_cents' => 10000,
        ], ['Idempotency-Key' => 'test-idem-prod-widget-0002']);
        $dupProduct->assertStatus(409);

        $warehouse = $this->actingAs($org['owner'])->postJson('/api/v1/warehouses', [
            'schema_version' => '1.0.0', 'code' => 'MAIN', 'name' => 'Main Warehouse', 'address' => '1 Storage Road, Windhoek',
        ], ['Idempotency-Key' => 'test-idem-wh-main-0001']);
        $warehouse->assertStatus(201);
    }

    public function test_a_receipt_movement_increases_balance_and_a_negative_result_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0002');
        $productId = $this->createProduct($org['owner']);
        $warehouseId = $this->createWarehouse($org['owner']);

        $receipt = $this->actingAs($org['owner'])->postJson('/api/v1/inventory/movements', [
            'schema_version' => '1.0.0', 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'movement_type' => 'RECEIPT',
            'quantity_micros' => 10_000_000, 'unit_cost_cents' => 10000, 'reference_type' => 'PO', 'reference_id' => (string) Str::uuid(),
            'reason' => 'Initial stock receipt.',
        ], ['Idempotency-Key' => 'test-idem-mv-receipt-0001']);
        $receipt->assertStatus(201);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity_micros' => 10_000_000, 'average_cost_cents' => 10000]);

        $issue = $this->actingAs($org['owner'])->postJson('/api/v1/inventory/movements', [
            'schema_version' => '1.0.0', 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'movement_type' => 'ISSUE',
            'quantity_micros' => 15_000_000, 'unit_cost_cents' => 0, 'reference_type' => 'SALE', 'reference_id' => (string) Str::uuid(),
            'reason' => 'Attempting to issue more than on hand.',
        ], ['Idempotency-Key' => 'test-idem-mv-issue-0001']);
        $issue->assertStatus(409);
    }

    public function test_transferring_stock_moves_both_legs_atomically_and_preserves_cost_basis(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0003');
        $productId = $this->createProduct($org['owner']);
        $mainWarehouseId = $this->createWarehouse($org['owner'], 'MAIN');
        $branchWarehouseId = $this->createWarehouse($org['owner'], 'BRANCH');

        $this->actingAs($org['owner'])->postJson('/api/v1/inventory/movements', [
            'schema_version' => '1.0.0', 'warehouse_id' => $mainWarehouseId, 'product_id' => $productId, 'movement_type' => 'RECEIPT',
            'quantity_micros' => 20_000_000, 'unit_cost_cents' => 15000, 'reference_type' => 'PO', 'reference_id' => (string) Str::uuid(), 'reason' => 'Stock receipt.',
        ], ['Idempotency-Key' => 'test-idem-mv-transfer-receipt-0001'])->assertStatus(201);

        $transfer = $this->actingAs($org['owner'])->postJson('/api/v1/inventory/transfers', [
            'schema_version' => '1.0.0', 'from_warehouse_id' => $mainWarehouseId, 'to_warehouse_id' => $branchWarehouseId,
            'product_id' => $productId, 'quantity_micros' => 5_000_000, 'reason' => 'Restocking the branch.',
        ], ['Idempotency-Key' => 'test-idem-transfer-0001']);

        $transfer->assertStatus(201)->assertJsonPath('resource.unit_cost_cents', 15000)->assertJsonCount(2, 'resource.movements');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $mainWarehouseId, 'product_id' => $productId, 'quantity_micros' => 15_000_000]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $branchWarehouseId, 'product_id' => $productId, 'quantity_micros' => 5_000_000, 'average_cost_cents' => 15000]);
    }

    public function test_availability_and_valuation_aggregate_correctly_across_warehouses(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0004');
        $productId = $this->createProduct($org['owner']);
        $warehouseId = $this->createWarehouse($org['owner']);
        $this->actingAs($org['owner'])->postJson('/api/v1/inventory/movements', [
            'schema_version' => '1.0.0', 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'movement_type' => 'RECEIPT',
            'quantity_micros' => 8_000_000, 'unit_cost_cents' => 25000, 'reference_type' => 'PO', 'reference_id' => (string) Str::uuid(), 'reason' => 'Stock receipt.',
        ], ['Idempotency-Key' => 'test-idem-mv-avail-0001'])->assertStatus(201);

        $availability = $this->actingAs($org['owner'])->getJson('/api/v1/inventory/availability');
        $availability->assertStatus(200)->assertJsonPath('products.0.total_quantity_micros', 8_000_000);

        $valuation = $this->actingAs($org['owner'])->getJson('/api/v1/inventory/valuation');
        // 8_000_000 micros (= 8 units) * 25000 cents average cost / 1_000_000 = 200000 cents.
        $valuation->assertStatus(200)->assertJsonPath('total_value_cents', 200000)->assertJsonPath('products.0.total_value_cents', 200000);
    }

    public function test_a_viewer_without_inventory_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0005');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-inv@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/products', [
            'schema_version' => '1.0.0', 'sku' => 'WIDGET', 'name' => 'Widget', 'unit_code' => 'EA',
            'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500, 'sales_price_cents' => 20000, 'cost_price_cents' => 10000,
        ], ['Idempotency-Key' => 'test-idem-prod-viewer-0001']);

        $response->assertStatus(403);
    }
}

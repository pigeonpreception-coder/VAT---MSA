<?php

namespace Tests\Feature\Operations;

use App\Models\FixedAsset;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Operations > Logistics Module
 * (App\Http\Controllers\Operations\LogisticsViewController /
 * resources/views/operations/logistics/index.blade.php) -- ported from
 * the source's own app/operations/logistics/page.tsx + components/
 * LogisticsManager.tsx.
 */
class LogisticsViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@logisticsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_logistics_page_requires_authentication(): void
    {
        $this->get('/operations/logistics')->assertRedirect('/login');
    }

    public function test_a_role_without_logistics_read_is_forbidden(): void
    {
        $org = $this->makeOrganisation('VAT-LOG-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Buyer User', 'email' => 'buyer@logisticsview.test',
            'password' => bcrypt('password'), 'role' => 'BUYER_USER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/operations/logistics')->assertForbidden();
    }

    public function test_creating_a_delivery_with_a_registered_vehicle_and_running_its_full_lifecycle(): void
    {
        $org = $this->makeOrganisation('VAT-LOG-0002');
        $vehicle = FixedAsset::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'asset_class' => 'MOVABLE',
            'asset_code' => 'VEH-010', 'category' => 'VEHICLE', 'description' => 'Delivery truck', 'location_or_address' => 'Main depot',
            'acquisition_date' => '2022-01-01', 'acquisition_cost_cents' => 300_000_00, 'current_value_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $org['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = $this->actingAs($org['owner'])->post('/operations/logistics', [
            'delivery_number' => 'DEL-001', 'reference_type' => 'INVOICE', 'reference_id' => (string) Str::uuid(),
            'origin' => 'Main warehouse', 'destination' => 'Customer site, Swakopmund', 'vehicle_asset_id' => $vehicle->id,
        ]);
        $create->assertRedirect(route('operations.logistics'));
        $this->assertDatabaseHas('logistics_deliveries', ['organisation_id' => $org['organisation']->id, 'delivery_number' => 'DEL-001', 'status' => 'PENDING']);
        $deliveryId = \App\Models\LogisticsDelivery::where('delivery_number', 'DEL-001')->firstOrFail()->id;

        $this->actingAs($org['owner'])->post("/operations/logistics/{$deliveryId}/dispatch")->assertRedirect(route('operations.logistics'));
        $this->assertDatabaseHas('logistics_deliveries', ['id' => $deliveryId, 'status' => 'IN_TRANSIT']);

        $this->actingAs($org['owner'])->post("/operations/logistics/{$deliveryId}/delivery")->assertRedirect(route('operations.logistics'));
        $this->assertDatabaseHas('logistics_deliveries', ['id' => $deliveryId, 'status' => 'DELIVERED']);

        // A delivered delivery cannot be dispatched again -- a friendly form error, not a raw exception.
        $this->actingAs($org['owner'])->post("/operations/logistics/{$deliveryId}/dispatch")->assertSessionHasErrors();
    }

    public function test_a_vehicle_asset_id_must_be_a_movable_asset_registered_in_the_same_organisation(): void
    {
        $orgA = $this->makeOrganisation('VAT-LOG-0003');
        $orgB = $this->makeOrganisation('VAT-LOG-0004');
        $foreignVehicle = FixedAsset::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $orgB['organisation']->id, 'asset_class' => 'MOVABLE',
            'asset_code' => 'VEH-020', 'category' => 'VEHICLE', 'description' => 'Other tenant truck', 'location_or_address' => 'Elsewhere',
            'acquisition_date' => '2022-01-01', 'acquisition_cost_cents' => 300_000_00, 'status' => 'ACTIVE',
            'created_by' => $orgB['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($orgA['owner'])->post('/operations/logistics', [
            'delivery_number' => 'DEL-002', 'reference_type' => 'OTHER', 'origin' => 'Depot', 'destination' => 'Site',
            'vehicle_asset_id' => $foreignVehicle->id,
        ]);

        $response->assertRedirect(route('operations.logistics'));
        $response->assertSessionHasErrors('delivery');
        $this->assertDatabaseMissing('logistics_deliveries', ['delivery_number' => 'DEL-002']);
    }

    public function test_cancelling_a_pending_delivery_records_the_reason(): void
    {
        $org = $this->makeOrganisation('VAT-LOG-0005');
        $this->actingAs($org['owner'])->post('/operations/logistics', [
            'delivery_number' => 'DEL-003', 'reference_type' => 'OTHER', 'origin' => 'Depot', 'destination' => 'Site',
        ])->assertRedirect(route('operations.logistics'));
        $deliveryId = \App\Models\LogisticsDelivery::where('delivery_number', 'DEL-003')->firstOrFail()->id;

        $this->actingAs($org['owner'])->post("/operations/logistics/{$deliveryId}/cancellation", ['reason' => 'Customer cancelled the order.'])
            ->assertRedirect(route('operations.logistics'));
        $this->assertDatabaseHas('logistics_deliveries', ['id' => $deliveryId, 'status' => 'CANCELLED', 'cancellation_reason' => 'Customer cancelled the order.']);
    }
}

<?php

namespace Tests\Feature\Operations;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the JSON API half of Operations > Logistics Module
 * (App\Http\Controllers\Operations\LogisticsController), ported from
 * app/api/v1/logistics-deliveries/{route,[id]/{route,dispatch,delivery,
 * cancellation}}/route.ts. Found via the same route-level source sweep as
 * FixedAssetApiTest -- Logistics had shipped only its Blade half
 * (see tests/Feature/Operations/LogisticsViewTest.php) until now.
 */
class LogisticsApiTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@logistics-api.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function deliveryPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'delivery_number' => 'DEL-API-001', 'reference_type' => 'OTHER',
            'origin' => 'Main depot, Windhoek', 'destination' => 'Customer site, Swakopmund',
        ], $overrides);
    }

    public function test_the_index_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/logistics-deliveries')->assertStatus(401);
    }

    public function test_an_owner_can_create_a_delivery_and_read_it_back(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0001');

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-0001']);

        $response->assertStatus(201)
            ->assertJsonPath('resource.delivery_number', 'DEL-API-001')
            ->assertJsonPath('resource.status', 'PENDING');
        $deliveryId = $response->json('resource.id');

        $show = $this->actingAs($org['owner'])->getJson("/api/v1/logistics-deliveries/{$deliveryId}");
        $show->assertStatus(200)->assertJsonPath('resource.delivery_number', 'DEL-API-001');
    }

    public function test_the_index_route_is_scoped_to_the_actors_own_organisation(): void
    {
        $orgA = $this->makeOrganisation('VAT-LGAPI-0002');
        $orgB = $this->makeOrganisation('VAT-LGAPI-0003');
        $this->actingAs($orgA['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(['delivery_number' => 'DEL-A-001']), ['Idempotency-Key' => 'test-idem-lg-api-a1']);
        $this->actingAs($orgB['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(['delivery_number' => 'DEL-B-001']), ['Idempotency-Key' => 'test-idem-lg-api-b1']);

        $response = $this->actingAs($orgA['owner'])->getJson('/api/v1/logistics-deliveries');

        $response->assertStatus(200)->assertJsonCount(1, 'resources')->assertJsonPath('resources.0.delivery_number', 'DEL-A-001');
    }

    public function test_a_duplicate_delivery_number_within_the_same_organisation_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0004');
        $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-dup1'])->assertStatus(201);

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(['destination' => 'A different site']), ['Idempotency-Key' => 'test-idem-lg-api-dup2']);

        $response->assertStatus(409);
    }

    public function test_a_reference_type_other_than_other_requires_a_reference_id(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0005');

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload([
            'delivery_number' => 'DEL-NOREF-001', 'reference_type' => 'INVOICE',
        ]), ['Idempotency-Key' => 'test-idem-lg-api-noref']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'REFERENCE_ID_REQUIRED');
    }

    public function test_a_viewer_without_manage_permission_cannot_create_a_delivery(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0006');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Buyer Viewer', 'email' => 'buyer-viewer@logistics-api.test',
            'password' => bcrypt('password'), 'role' => 'BUYER_USER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-forbidden']);

        $response->assertStatus(403);
    }

    public function test_the_full_dispatch_deliver_lifecycle(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0007');
        $created = $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-lc'])->assertStatus(201);
        $deliveryId = $created->json('resource.id');

        $dispatch = $this->actingAs($org['owner'])->postJson("/api/v1/logistics-deliveries/{$deliveryId}/dispatch", [], ['Idempotency-Key' => 'test-idem-lg-api-dispatch']);
        $dispatch->assertStatus(200)->assertJsonPath('resource.status', 'IN_TRANSIT');

        $deliver = $this->actingAs($org['owner'])->postJson("/api/v1/logistics-deliveries/{$deliveryId}/delivery", [], ['Idempotency-Key' => 'test-idem-lg-api-deliver']);
        $deliver->assertStatus(200)->assertJsonPath('resource.status', 'DELIVERED');

        $cancelAfterDelivered = $this->actingAs($org['owner'])->postJson("/api/v1/logistics-deliveries/{$deliveryId}/cancellation", [
            'schema_version' => '1.0.0', 'reason' => 'Too late, already delivered.',
        ], ['Idempotency-Key' => 'test-idem-lg-api-late-cancel']);
        $cancelAfterDelivered->assertStatus(422);
    }

    public function test_cancelling_a_pending_delivery(): void
    {
        $org = $this->makeOrganisation('VAT-LGAPI-0008');
        $created = $this->actingAs($org['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-cancel-create'])->assertStatus(201);
        $deliveryId = $created->json('resource.id');

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/logistics-deliveries/{$deliveryId}/cancellation", [
            'schema_version' => '1.0.0', 'reason' => 'Customer cancelled the order.',
        ], ['Idempotency-Key' => 'test-idem-lg-api-cancel']);

        $response->assertStatus(200)->assertJsonPath('resource.status', 'CANCELLED');
    }

    public function test_a_taxpayer_cannot_read_or_act_on_another_organisations_delivery(): void
    {
        $orgA = $this->makeOrganisation('VAT-LGAPI-0009');
        $orgB = $this->makeOrganisation('VAT-LGAPI-0010');
        $created = $this->actingAs($orgB['owner'])->postJson('/api/v1/logistics-deliveries', $this->deliveryPayload(), ['Idempotency-Key' => 'test-idem-lg-api-cross'])->assertStatus(201);
        $deliveryId = $created->json('resource.id');

        $this->actingAs($orgA['owner'])->getJson("/api/v1/logistics-deliveries/{$deliveryId}")->assertStatus(404);
        $this->actingAs($orgA['owner'])->postJson("/api/v1/logistics-deliveries/{$deliveryId}/dispatch", [], ['Idempotency-Key' => 'test-idem-lg-api-cross-dispatch'])->assertStatus(404);
    }
}

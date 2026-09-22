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
 * Covers the JSON API half of Operations > Immovable/Movable Asset
 * Management (App\Http\Controllers\Operations\FixedAssetController), ported
 * from app/api/v1/fixed-assets/{route,[id]/{route,valuation,maintenance,
 * restoration,disposal}}/route.ts. Found unported via a route-level source
 * sweep: unlike every other business module in this port, fixed assets had
 * only ever shipped its Blade half (see
 * tests/Feature/Operations/FixedAssetViewTest.php) -- this file covers the
 * same App\Services\Operations\FixedAssetService through the JSON surface
 * instead, so it does not re-cover FixedAssetViewTest's own race-condition/
 * concurrency-simulation ground.
 */
class FixedAssetApiTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@fixedasset-api.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function assetPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'asset_class' => 'MOVABLE', 'asset_code' => 'VEH-API-001', 'category' => 'VEHICLE',
            'description' => 'API-registered delivery van', 'location_or_address' => 'Main depot',
            'acquisition_date' => '2022-06-01', 'acquisition_cost_cents' => 250_000_00,
        ], $overrides);
    }

    public function test_the_index_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/fixed-assets')->assertStatus(401);
    }

    public function test_an_owner_can_register_an_asset_and_read_it_back(): void
    {
        $org = $this->makeOrganisation('VAT-FAAPI-0001');

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(), ['Idempotency-Key' => 'test-idem-fa-api-0001']);

        $response->assertStatus(201)
            ->assertJsonPath('resource.asset_code', 'VEH-API-001')
            ->assertJsonPath('resource.status', 'ACTIVE');
        $assetId = $response->json('resource.id');

        $show = $this->actingAs($org['owner'])->getJson("/api/v1/fixed-assets/{$assetId}");
        $show->assertStatus(200)->assertJsonPath('resource.asset_code', 'VEH-API-001');
    }

    public function test_the_index_route_is_scoped_to_the_actors_own_organisation_and_filters_by_asset_class(): void
    {
        $orgA = $this->makeOrganisation('VAT-FAAPI-0002');
        $orgB = $this->makeOrganisation('VAT-FAAPI-0003');
        $this->actingAs($orgA['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(['asset_code' => 'VEH-A-001']), ['Idempotency-Key' => 'test-idem-fa-api-a1']);
        $this->actingAs($orgA['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload([
            'asset_class' => 'IMMOVABLE', 'asset_code' => 'BLD-A-001', 'category' => 'BUILDING', 'location_or_address' => '1 Head Office Way',
        ]), ['Idempotency-Key' => 'test-idem-fa-api-a2']);
        $this->actingAs($orgB['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(['asset_code' => 'VEH-B-001']), ['Idempotency-Key' => 'test-idem-fa-api-b1']);

        $movable = $this->actingAs($orgA['owner'])->getJson('/api/v1/fixed-assets?asset_class=MOVABLE');
        $movable->assertStatus(200)->assertJsonCount(1, 'resources')->assertJsonPath('resources.0.asset_code', 'VEH-A-001');

        $all = $this->actingAs($orgA['owner'])->getJson('/api/v1/fixed-assets');
        $all->assertStatus(200)->assertJsonCount(2, 'resources');
    }

    public function test_a_duplicate_asset_code_within_the_same_organisation_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-FAAPI-0004');
        $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(), ['Idempotency-Key' => 'test-idem-fa-api-dup1'])->assertStatus(201);

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(['description' => 'A different van']), ['Idempotency-Key' => 'test-idem-fa-api-dup2']);

        $response->assertStatus(409);
    }

    public function test_replaying_the_same_idempotency_key_and_payload_returns_the_identical_resource(): void
    {
        $org = $this->makeOrganisation('VAT-FAAPI-0005');
        $payload = $this->assetPayload();

        $first = $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $payload, ['Idempotency-Key' => 'test-idem-fa-api-replay'])->assertStatus(201);
        $second = $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $payload, ['Idempotency-Key' => 'test-idem-fa-api-replay']);

        $second->assertStatus(201)->assertJsonPath('resource.id', $first->json('resource.id'));
        $this->assertSame(1, \App\Models\FixedAsset::where('organisation_id', $org['organisation']->id)->where('asset_code', 'VEH-API-001')->count());
    }

    public function test_a_viewer_without_manage_permission_cannot_register_an_asset(): void
    {
        $org = $this->makeOrganisation('VAT-FAAPI-0006');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Buyer Viewer', 'email' => 'buyer-viewer@fixedasset-api.test',
            'password' => bcrypt('password'), 'role' => 'BUYER_USER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/fixed-assets', $this->assetPayload(), ['Idempotency-Key' => 'test-idem-fa-api-forbidden']);

        $response->assertStatus(403);
    }

    public function test_the_full_valuation_maintenance_restoration_disposal_lifecycle(): void
    {
        $org = $this->makeOrganisation('VAT-FAAPI-0007');
        $created = $this->actingAs($org['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(), ['Idempotency-Key' => 'test-idem-fa-api-lc'])->assertStatus(201);
        $assetId = $created->json('resource.id');

        $valuation = $this->actingAs($org['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/valuation", [
            'schema_version' => '1.0.0', 'current_value_cents' => 200_000_00,
        ], ['Idempotency-Key' => 'test-idem-fa-api-val']);
        $valuation->assertStatus(200)->assertJsonPath('resource.current_value_cents', 200_000_00);

        $maintenance = $this->actingAs($org['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/maintenance", [], ['Idempotency-Key' => 'test-idem-fa-api-maint']);
        $maintenance->assertStatus(200)->assertJsonPath('resource.status', 'UNDER_MAINTENANCE');

        $restoration = $this->actingAs($org['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/restoration", [], ['Idempotency-Key' => 'test-idem-fa-api-rest']);
        $restoration->assertStatus(200)->assertJsonPath('resource.status', 'ACTIVE');

        $disposal = $this->actingAs($org['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/disposal", [
            'schema_version' => '1.0.0', 'reason' => 'Beyond economical repair.',
        ], ['Idempotency-Key' => 'test-idem-fa-api-disp']);
        $disposal->assertStatus(200)->assertJsonPath('resource.status', 'DISPOSED');

        $reflag = $this->actingAs($org['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/maintenance", [], ['Idempotency-Key' => 'test-idem-fa-api-reflag']);
        $reflag->assertStatus(422);
    }

    public function test_a_taxpayer_cannot_read_or_act_on_another_organisations_asset(): void
    {
        $orgA = $this->makeOrganisation('VAT-FAAPI-0008');
        $orgB = $this->makeOrganisation('VAT-FAAPI-0009');
        $created = $this->actingAs($orgB['owner'])->postJson('/api/v1/fixed-assets', $this->assetPayload(), ['Idempotency-Key' => 'test-idem-fa-api-cross'])->assertStatus(201);
        $assetId = $created->json('resource.id');

        $this->actingAs($orgA['owner'])->getJson("/api/v1/fixed-assets/{$assetId}")->assertStatus(404);
        $this->actingAs($orgA['owner'])->postJson("/api/v1/fixed-assets/{$assetId}/maintenance", [], ['Idempotency-Key' => 'test-idem-fa-api-cross-maint'])->assertStatus(404);
    }
}

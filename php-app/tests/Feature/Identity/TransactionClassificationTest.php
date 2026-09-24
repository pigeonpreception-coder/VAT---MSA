<?php

namespace Tests\Feature\Identity;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gap-finding pass (2026-09-24): app/api/v1/counterparties/classification/
 * route.ts had no Laravel route at all -- see
 * App\Http\Controllers\Identity\TransactionClassificationController's own
 * doc comment. The underlying logic (App\Support\Business\
 * TransactionClassifier) already existed, reused internally by
 * SupplierVerificationService, but was never exposed as its own endpoint.
 */
class TransactionClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeActor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Actor', 'email' => 'actor@txclass.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/counterparties/classification?vat_number=VAT-TXCLASS-0001')->assertStatus(401);
    }

    public function test_a_role_without_invoices_submit_is_denied(): void
    {
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@txclass.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->getJson('/api/v1/counterparties/classification?vat_number=VAT-TXCLASS-0001')
            ->assertStatus(403);
    }

    public function test_a_malformed_vat_number_is_rejected(): void
    {
        $actor = $this->makeActor();

        $this->actingAs($actor)->getJson('/api/v1/counterparties/classification?vat_number=**')
            ->assertStatus(422);
    }

    public function test_an_unknown_vat_number_classifies_as_fully_inactive(): void
    {
        $actor = $this->makeActor();

        $response = $this->actingAs($actor)->getJson('/api/v1/counterparties/classification?vat_number=VAT-TXCLASS-UNKNOWN');

        $response->assertOk();
        $response->assertJsonPath('classification.vat_number', 'VAT-TXCLASS-UNKNOWN');
        $response->assertJsonPath('classification.taxpayer_active', false);
        $response->assertJsonPath('classification.organisation_active', false);
        $response->assertJsonPath('classification.capabilities', []);
        $response->assertJsonPath('classification.can_act_as_seller', false);
        $response->assertJsonPath('classification.can_act_as_buyer', false);
    }

    public function test_an_active_counterparty_with_capabilities_classifies_correctly(): void
    {
        $actor = $this->makeActor();
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-TXCLASS-0002', 'tin' => 'TIN-TXCLASS-0002',
            'legal_name' => 'TxClass Trading Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'txclass@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'effective_to' => null,
        ]);

        $response = $this->actingAs($actor)->getJson('/api/v1/counterparties/classification?vat_number=vat-txclass-0002');

        $response->assertOk();
        $response->assertJsonPath('classification.vat_number', 'VAT-TXCLASS-0002');
        $response->assertJsonPath('classification.taxpayer_active', true);
        $response->assertJsonPath('classification.organisation_active', true);
        $response->assertJsonPath('classification.capabilities', ['SELLER']);
        $response->assertJsonPath('classification.can_act_as_seller', true);
        $response->assertJsonPath('classification.can_act_as_buyer', false);
    }
}

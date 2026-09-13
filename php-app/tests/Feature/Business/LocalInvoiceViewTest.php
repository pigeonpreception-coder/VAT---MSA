<?php

namespace Tests\Feature\Business;

use App\Models\ApiClient;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Local Invoices
 * (App\Http\Controllers\Business\LocalInvoiceViewController /
 * resources/views/invoice-management/local.blade.php) -- the user's own
 * explicit request that local invoices issued/received via a taxpayer's
 * own private POS (through a real, credential-authenticated API -- see
 * tests/Feature/Integration/PosInvoiceApiTest.php) or via VAT-MSA's own
 * built-in POS module be visible together in real time. This file covers
 * the register rendering and the credential-management card
 * (App\Services\Integration\PosApiClientService); the external ingestion
 * endpoint itself is covered separately.
 */
class LocalInvoiceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, admin: User, accountant: User} */
    private function makeOrganisation(string $vatNumber, array $capabilities = ['BUYER', 'SELLER']): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach ($capabilities as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@liview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@liview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin', 'accountant');
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0',
            'invoice_number' => 'INV-'.Str::random(8),
            'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SUP-0001']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUS-0001']]],
            'issue_date' => '2026-09-01',
            'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']],
            ],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    public function test_the_local_invoices_page_requires_authentication(): void
    {
        $this->get('/invoice-management/local')->assertRedirect('/login');
    }

    public function test_a_role_without_invoices_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-LI-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Invoices Role', 'email' => 'noinv@liview.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/invoice-management/local')->assertForbidden();
    }

    public function test_the_page_renders_issued_and_received_invoices_with_source_labels(): void
    {
        $seller = $this->makeOrganisation('VAT-LI-0002');
        $buyer = $this->makeOrganisation('VAT-LI-0003');

        $this->actingAs($seller['admin'])->postJson('/api/v1/invoices', $this->invocePayloadFor('VAT-LI-0002', 'VAT-LI-0003'), ['Idempotency-Key' => 'test-idem-'.Str::random(20)])
            ->assertStatus(201);

        $sellerResponse = $this->actingAs($seller['admin'])->get('/invoice-management/local');
        $sellerResponse->assertOk()->assertViewIs('invoice-management.local');
        $sellerResponse->assertSee('Customer Co');
        $sellerResponse->assertSee('Manual / other system');

        $buyerResponse = $this->actingAs($buyer['admin'])->get('/invoice-management/local');
        $buyerResponse->assertOk();
        $buyerResponse->assertSee('Supplier Co');
    }

    private function invocePayloadFor(string $supplierVat, string $customerVat): array
    {
        return $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]);
    }

    public function test_a_role_without_integrations_manage_does_not_see_the_credentials_card(): void
    {
        $org = $this->makeOrganisation('VAT-LI-0004');

        $response = $this->actingAs($org['accountant'])->get('/invoice-management/local');

        $response->assertOk();
        $response->assertDontSee('Private POS API credentials');
        $this->actingAs($org['accountant'])->post('/invoice-management/local/credentials', ['name' => 'Denied till'])->assertForbidden();
    }

    public function test_an_admin_can_issue_a_pos_api_credential_and_the_secret_is_shown_once(): void
    {
        $org = $this->makeOrganisation('VAT-LI-0005');

        $response = $this->actingAs($org['admin'])->post('/invoice-management/local/credentials', ['name' => 'Front counter till']);

        $response->assertRedirect('/invoice-management/local');
        $response->assertSessionHas('status');
        $response->assertSessionHas('newCredential');
        $this->assertDatabaseHas('api_clients', ['organisation_id' => $org['organisation']->id, 'name' => 'Front counter till', 'status' => 'ACTIVE']);

        $follow = $this->actingAs($org['admin'])->get('/invoice-management/local');
        $follow->assertSee('Front counter till');
        $follow->assertSee('Copy this secret now');
    }

    public function test_issuing_a_credential_for_an_organisation_without_seller_capability_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-LI-0006', ['BUYER']);

        $response = $this->actingAs($org['admin'])->post('/invoice-management/local/credentials', ['name' => 'Buyer-only till']);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('api_clients', ['organisation_id' => $org['organisation']->id]);
    }

    public function test_an_admin_can_revoke_a_credential(): void
    {
        $org = $this->makeOrganisation('VAT-LI-0007');
        $this->actingAs($org['admin'])->post('/invoice-management/local/credentials', ['name' => 'Till to revoke']);
        $client = ApiClient::where('organisation_id', $org['organisation']->id)->firstOrFail();

        $response = $this->actingAs($org['admin'])->post("/invoice-management/local/credentials/{$client->id}/revocation", ['reason' => 'Terminal decommissioned']);

        $response->assertRedirect('/invoice-management/local');
        $this->assertDatabaseHas('api_clients', ['id' => $client->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('credential_refs', ['api_client_id' => $client->id, 'status' => 'REVOKED']);
    }

    public function test_an_organisation_cannot_revoke_another_organisations_credential(): void
    {
        $orgA = $this->makeOrganisation('VAT-LI-0008');
        $orgB = $this->makeOrganisation('VAT-LI-0009');
        $this->actingAs($orgA['admin'])->post('/invoice-management/local/credentials', ['name' => 'Org A till']);
        $client = ApiClient::where('organisation_id', $orgA['organisation']->id)->firstOrFail();

        $response = $this->actingAs($orgB['admin'])->post("/invoice-management/local/credentials/{$client->id}/revocation", ['reason' => 'Not mine to revoke']);

        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('api_clients', ['id' => $client->id, 'status' => 'ACTIVE']);
    }
}

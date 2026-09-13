<?php

namespace Tests\Feature\Integration;

use App\Models\ApiClient;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Integration\PosApiClientService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real, credential-authenticated external POS ingestion API
 * (App\Http\Middleware\AuthenticatePosApiClient / App\Http\Controllers\
 * Integration\PosInvoiceController, routes/api.php) -- the user's own
 * explicit request that a taxpayer's own private Point-of-Sale system be
 * able to push invoices here in real time through a real API, not a
 * session. No Idempotency-Key duplicate-replay coverage here (that's
 * InvoiceService::submit()'s own, already covered by
 * tests/Feature/Invoice/InvoiceCertificationTest.php); this file's own
 * job is the credential boundary: who can call this endpoint at all, and
 * what happens to invoice data when they can't.
 */
class PosInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, admin: User} */
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@posapi.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin');
    }

    /** @return array{client_key: string, client_secret: string, api_client_id: string} */
    private function issueCredential(Organisation $organisation, User $actor): array
    {
        return app(PosApiClientService::class)->issue($organisation, $actor, 'Front counter till', (string) Str::uuid());
    }

    private function invoicePayload(string $supplierVat, ?string $customerVat = null): array
    {
        $customer = $customerVat
            ? ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $customerVat]]]
            : ['name' => 'Walk-in customer', 'identifiers' => [['type' => 'OTHER', 'value' => 'CONSUMER']]];

        return [
            'schema_version' => '1.0.0',
            'invoice_number' => 'POS-'.Str::random(8),
            'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'should-be-overridden', 'document_id' => 'pos-doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $supplierVat]]],
            'customer' => $customer,
            'issue_date' => '2026-09-01',
            'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Retail sale', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '200.00', 'net_amount' => '200.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '200.00', 'tax_amount' => '30.00']],
            ],
            'totals' => ['line_net_amount' => '200.00', 'tax_exclusive_amount' => '200.00', 'tax_amount' => '30.00', 'tax_inclusive_amount' => '230.00', 'payable_amount' => '230.00'],
        ];
    }

    public function test_a_request_with_no_authorization_header_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-POSAPI-0001');

        $this->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0001'))->assertStatus(401);
    }

    public function test_a_request_with_an_unknown_client_key_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer pos_unknown.somesecret'])
            ->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0002'))
            ->assertStatus(401);
    }

    public function test_a_request_with_the_wrong_secret_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-POSAPI-0003');
        $credential = $this->issueCredential($org['organisation'], $org['admin']);

        $this->withHeaders(['Authorization' => "Bearer {$credential['client_key']}.wrong-secret"])
            ->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0003'))
            ->assertStatus(401);

        $this->assertDatabaseMissing('invoices', ['supplier_vat_number' => 'VAT-POSAPI-0003']);
    }

    public function test_a_revoked_credential_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-POSAPI-0004');
        $credential = $this->issueCredential($org['organisation'], $org['admin']);
        app(PosApiClientService::class)->revoke($org['organisation'], $credential['api_client_id'], $org['admin'], 'Terminal decommissioned', (string) Str::uuid());

        $this->withHeaders(['Authorization' => "Bearer {$credential['client_key']}.{$credential['client_secret']}"])
            ->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0004'))
            ->assertStatus(401);
    }

    public function test_a_valid_credential_certifies_an_invoice_in_real_time_tagged_with_the_external_pos_source(): void
    {
        $seller = $this->makeOrganisation('VAT-POSAPI-0005');
        $credential = $this->issueCredential($seller['organisation'], $seller['admin']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$credential['client_key']}.{$credential['client_secret']}", 'Idempotency-Key' => 'pos-idem-'.Str::random(20)])
            ->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0005'));

        $response->assertStatus(201)->assertJsonPath('processing_status', 'CERTIFIED');
        $this->assertDatabaseHas('invoices', [
            'supplier_vat_number' => 'VAT-POSAPI-0005', 'source_system' => 'EXTERNAL-POS:'.$credential['client_key'],
        ]);
    }

    public function test_a_valid_credential_with_a_known_customer_vat_number_produces_a_matched_invoice_for_both_parties(): void
    {
        $seller = $this->makeOrganisation('VAT-POSAPI-0006');
        $buyer = $this->makeOrganisation('VAT-POSAPI-0007');
        $credential = $this->issueCredential($seller['organisation'], $seller['admin']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$credential['client_key']}.{$credential['client_secret']}", 'Idempotency-Key' => 'pos-idem-'.Str::random(20)])
            ->postJson('/api/pos/v1/invoices', $this->invoicePayload('VAT-POSAPI-0006', 'VAT-POSAPI-0007'));

        $response->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
        $invoiceId = $response->json('invoice_id');
        $this->assertDatabaseHas('ledger_entries', ['invoice_id' => $invoiceId, 'taxpayer_id' => $seller['taxpayer']->id, 'entry_type' => 'OUTPUT_VAT']);
        $this->assertDatabaseHas('ledger_entries', ['invoice_id' => $invoiceId, 'taxpayer_id' => $buyer['taxpayer']->id, 'entry_type' => 'INPUT_VAT']);

        $buyerRegister = $this->actingAs($buyer['admin'])->get('/invoice-management/local');
        $buyerRegister->assertOk()->assertSee('External POS (API)');
    }
}

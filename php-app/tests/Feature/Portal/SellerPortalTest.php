<?php

namespace Tests\Feature\Portal;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Seller portal dashboard
 * (App\Http\Controllers\Portal\SellerPortalController /
 * resources/views/portal/seller.blade.php / App\Services\Portal\
 * SellerPortalSnapshotService) -- ported from the source's own
 * app/portal/seller/page.tsx. Reuses BusinessPartyAndQuotationTest's own
 * quotation-lifecycle fixtures and InvoiceViewTest's own "certify via
 * the real command" convention -- this file's own job is proving the
 * portal-access gate and the view's own rendering (DashboardSnapshotService
 * and VatLifecycleService are already covered end to end elsewhere).
 */
class SellerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, array $capabilities = ['SELLER']): array
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0',
            'invoice_number' => 'INV-SELLERPORTAL-'.Str::random(8),
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

    private function certifyInvoice(User $supplierOwner, string $supplierVat, string $customerVat): string
    {
        $response = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'test-idem-sellerportal-inv-'.Str::random(20)]);
        $response->assertStatus(201);

        return $response->json('invoice_id');
    }

    private function partyPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'display_name' => 'Acme Customer', 'legal_name' => 'Acme Customer (Pty) Ltd',
            'vat_number' => 'VAT-SELLERPORTAL-CUST', 'email' => 'ap@acme.test', 'relationships' => ['CUSTOMER'],
        ], $overrides);
    }

    private function createCustomerParty(User $owner, string $vatNumber): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/business-parties', $this->partyPayload(['vat_number' => $vatNumber]), ['Idempotency-Key' => 'test-idem-sellerportal-cust-'.$vatNumber])
            ->assertStatus(201)->json('resource.id');
    }

    private function quotationPayload(string $customerPartyId, array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'customer_party_id' => $customerPartyId, 'quotation_number' => 'QUO-SELLERPORTAL-0001',
            'currency' => 'NAD', 'issue_date' => '2026-09-01', 'valid_until' => '2026-09-30',
            'lines' => [['description' => 'Consulting services', 'quantity_micros' => 1_000_000, 'unit_code' => 'EA', 'unit_price_cents' => 100_000, 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500]],
        ], $overrides);
    }

    public function test_the_seller_portal_requires_authentication(): void
    {
        $this->get('/portal/seller')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_seller_portals_list_is_denied(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor@sellerportal.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($auditor)->get('/portal/seller')->assertForbidden();
    }

    public function test_a_taxpayer_owner_without_seller_capability_is_denied(): void
    {
        $party = $this->makeTradingParty('VAT-SELLERPORTAL-0001', capabilities: []);

        $this->actingAs($party['owner'])->get('/portal/seller')->assertForbidden();
    }

    public function test_the_seller_portal_renders_invoices_quotations_and_vat_metrics(): void
    {
        $party = $this->makeTradingParty('VAT-SELLERPORTAL-0002');
        $this->certifyInvoice($party['owner'], 'VAT-SELLERPORTAL-0002', 'VAT-SELLERPORTAL-CUS-0002');

        $customerPartyId = $this->createCustomerParty($party['owner'], 'VAT-SELLERPORTAL-QCUST-0002');
        $quotationId = $this->actingAs($party['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-sellerportal-quo-create-0001'])
            ->assertStatus(201)->json('resource.id');
        $this->actingAs($party['owner'])->postJson("/api/v1/quotations/{$quotationId}/sending", [], ['Idempotency-Key' => 'test-idem-sellerportal-quo-send-0001'])->assertStatus(200);
        $this->actingAs($party['owner'])->postJson("/api/v1/quotations/{$quotationId}/accept", [], ['Idempotency-Key' => 'test-idem-sellerportal-quo-accept-0001'])->assertStatus(200);

        $periodId = (string) Str::uuid();
        VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $party['organisation']->id, 'taxpayer_id' => $party['taxpayer']->id,
            'period_code' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        VatReturnVersion::create([
            'id' => (string) Str::uuid(), 'vat_period_id' => $periodId, 'organisation_id' => $party['organisation']->id, 'taxpayer_id' => $party['taxpayer']->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 15000, 'input_tax_cents' => 0, 'adjustment_cents' => 0, 'net_payable_cents' => 15000,
            'status' => 'DRAFT', 'ledger_snapshot_hash' => str_repeat('c', 64), 'generated_by' => $party['owner']->id, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($party['owner'])->get('/portal/seller');

        $response->assertOk()->assertViewIs('portal.seller');
        $response->assertSee('Sales, certification and output VAT position');
        $response->assertSee('Supplier Co');
        $response->assertSee('Customer Co');
        $response->assertSee('NAD 150.00'); // tax column + output VAT metric
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
        $snapshot = $response->viewData('snapshot');
        $this->assertSame(1, $snapshot['dashboard']['metrics']['invoice_count']);
        $this->assertSame(1, $snapshot['quotations']['count']);
        // 100_000 net + 15_000 tax (15% STANDARD rate) -- quoted_value_cents sums total_cents
        // (tax-inclusive), matching business-repository.ts's own SUM(total_cents) exactly.
        $this->assertSame(115000, $snapshot['quotations']['quoted_value_cents']); // ACCEPTED status counts toward the pipeline
    }

    public function test_the_seller_portals_quotation_metrics_are_scoped_to_the_actors_own_organisation(): void
    {
        $partyA = $this->makeTradingParty('VAT-SELLERPORTAL-0003');
        $partyB = $this->makeTradingParty('VAT-SELLERPORTAL-0004');
        $customerA = $this->createCustomerParty($partyA['owner'], 'VAT-SELLERPORTAL-QCUST-A');
        $customerB = $this->createCustomerParty($partyB['owner'], 'VAT-SELLERPORTAL-QCUST-B');
        $this->actingAs($partyA['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerA), ['Idempotency-Key' => 'test-idem-sellerportal-quo-scope-a'])->assertStatus(201);
        $this->actingAs($partyB['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerB), ['Idempotency-Key' => 'test-idem-sellerportal-quo-scope-b'])->assertStatus(201);

        $response = $this->actingAs($partyA['owner'])->get('/portal/seller');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('snapshot')['quotations']['count']);
    }
}

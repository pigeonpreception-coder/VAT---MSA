<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Country;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\TaxAuthority;
use App\Models\TaxJurisdiction;
use App\Models\TaxRuleSet;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multi-tenant SaaS pivot phase 3 (2026-09-24): covers the two hardcoded
 * literals phase 2's own PR description named as this phase's job --
 * VatLifecycleService::generateReturn()'s jurisdiction filter and
 * InvoiceCalculator's currency gate -- now actually resolving from an
 * organisation's tax_authority_id (see Organisation::jurisdictionCountryCode()/
 * currencyCode()'s own doc comments) instead of a hardcoded 'NA'/'NAD'.
 *
 * Builds a second, wholly fictitious tax authority (country 'ZT', currency
 * 'ZTD') to prove the resolution is genuinely dynamic, not just a
 * passthrough that happens to equal Namibia's own values -- and keeps one
 * test on the existing NamRA/Namibia default to prove today's only real
 * tenant sees no behaviour change at all.
 */
class JurisdictionAwareCalculationTest extends TestCase
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
    private function makeTradingParty(string $vatNumber, ?string $taxAuthorityId = null, array $capabilities = ['BUYER', 'SELLER']): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $attributes = ['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE'];
        if ($taxAuthorityId) {
            $attributes['tax_authority_id'] = $taxAuthorityId;
        }
        $organisation = Organisation::create($attributes);
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

    /**
     * A second, fictitious tax authority -- country 'ZT'/currency 'ZTD' --
     * proving the resolution genuinely varies by tenant. DB::table()->insert()
     * throughout, not Eloquent create(): TaxJurisdiction/TaxAuthority
     * deliberately omit HasUuids (see either model's own doc comment; no
     * real command creates a row through them), so Eloquent's PK handling
     * defaults to auto-increment and silently mishandles a custom string
     * id -- the same gotcha OrganisationTaxAuthorityTest's own doc comment
     * already documents for TaxAuthority specifically.
     */
    private function makeSecondTaxAuthority(): TaxAuthority
    {
        Country::create(['code' => 'ZT', 'iso3_code' => 'ZTS', 'name' => 'Zambesi Test', 'currency_code' => 'ZTD', 'status' => 'ACTIVE']);
        DB::table('tax_jurisdictions')->insert(['id' => 'tax-jurisdiction-zt-national', 'country_code' => 'ZT', 'code' => 'ZT-NATIONAL', 'name' => 'Zambesi Test national jurisdiction', 'status' => 'ACTIVE', 'created_at' => now()]);
        DB::table('tax_authorities')->insert(['id' => 'tax-authority-zt-test', 'jurisdiction_id' => 'tax-jurisdiction-zt-national', 'code' => 'ZTRA', 'name' => 'Zambesi Test Revenue Authority', 'status' => 'ACTIVE', 'created_at' => now()]);

        return TaxAuthority::findOrFail('tax-authority-zt-test');
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-'.Str::random(8), 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SUP-0001']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUS-0001']]],
            'issue_date' => '2026-09-01', 'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']],
            ],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    public function test_a_second_tenants_invoice_is_certified_in_its_own_currency_not_nad(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        $supplier = $this->makeTradingParty('VAT-ZT-SUP-0001', $authority->id);
        $this->makeTradingParty('VAT-ZT-CUS-0001', $authority->id);

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-ZT-SUP-0001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-ZT-CUS-0001']]],
            'currency' => 'ZTD',
        ]), ['Idempotency-Key' => 'test-idem-zt-currency-0001']);

        $response->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
        $this->assertDatabaseHas('invoices', ['id' => $response->json('invoice_id'), 'currency' => 'ZTD']);
    }

    public function test_a_second_tenants_invoice_in_nad_is_now_rejected_as_a_jurisdiction_mismatch(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        $supplier = $this->makeTradingParty('VAT-ZT-SUP-0002', $authority->id);
        $this->makeTradingParty('VAT-ZT-CUS-0002', $authority->id);

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-ZT-SUP-0002']]],
            'customer' => ['identifiers' => [['value' => 'VAT-ZT-CUS-0002']]],
            'currency' => 'NAD',
        ]), ['Idempotency-Key' => 'test-idem-zt-currency-0002']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURRENCY_JURISDICTION_MISMATCH')
            ->assertJsonPath('errors.0.message', "VAT certification requires ZTD currency for this taxpayer's jurisdiction.");
    }

    /** The current, only real tenant (NamRA/Namibia) must see zero behaviour change from this phase. */
    public function test_a_namra_tenants_invoice_still_requires_nad_exactly_as_before(): void
    {
        $supplier = $this->makeTradingParty('VAT-NA-SUP-0001');
        $this->makeTradingParty('VAT-NA-CUS-0001');

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-NA-SUP-0001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-NA-CUS-0001']]],
            'currency' => 'USD',
        ]), ['Idempotency-Key' => 'test-idem-na-currency-0001']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURRENCY_JURISDICTION_MISMATCH')
            ->assertJsonPath('errors.0.message', "VAT certification requires NAD currency for this taxpayer's jurisdiction.");
    }

    public function test_return_generation_resolves_the_period_organisations_own_jurisdictions_rule_set(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        $ztRule = TaxRuleSet::create([
            'id' => (string) Str::uuid(), 'jurisdiction' => 'ZT', 'version' => 'ZT-VAT-PILOT-2026.1',
            'effective_from' => '2026-01-01', 'effective_to' => null, 'standard_rate_bps' => 1500,
            'legal_authority_reference' => null, 'status' => 'PILOT_CONTROLLED', 'approved_by' => null, 'approved_at' => null, 'created_at' => now(),
        ]);
        $supplier = $this->makeTradingParty('VAT-ZT-SUP-0003', $authority->id);
        $customer = $this->makeTradingParty('VAT-ZT-CUS-0003', $authority->id);

        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-ZT-SUP-0003']]],
            'customer' => ['identifiers' => [['value' => 'VAT-ZT-CUS-0003']]],
            'currency' => 'ZTD',
        ]), ['Idempotency-Key' => 'test-idem-zt-return-0001'])->assertStatus(201);

        $period = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], [
            'Idempotency-Key' => 'test-idem-zt-genreturn-0001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('resource.status', 'DRAFT')
            ->assertJsonPath('resource.tax_rule_set_id', $ztRule->id);
        $this->assertDatabaseHas('vat_return_versions', ['vat_period_id' => $period->id, 'tax_rule_set_id' => $ztRule->id]);
    }

    /** The current, only real tenant's return generation must still resolve the NA pilot rule, exactly as before. */
    public function test_return_generation_for_a_namra_tenant_still_resolves_the_na_rule_set(): void
    {
        $supplier = $this->makeTradingParty('VAT-NA-SUP-1001');
        $customer = $this->makeTradingParty('VAT-NA-CUS-1001');
        $naRuleId = TaxRuleSet::where('jurisdiction', 'NA')->value('id');

        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-NA-SUP-1001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-NA-CUS-1001']]],
        ]), ['Idempotency-Key' => 'test-idem-na-return-0001'])->assertStatus(201);

        $period = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], [
            'Idempotency-Key' => 'test-idem-na-genreturn-0001',
        ]);

        $response->assertStatus(201)->assertJsonPath('resource.tax_rule_set_id', $naRuleId);
    }
}

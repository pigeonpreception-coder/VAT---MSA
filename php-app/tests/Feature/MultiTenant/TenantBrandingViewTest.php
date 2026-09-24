<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Country;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\TaxAuthority;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multi-tenant SaaS pivot phase 4 (2026-09-24): covers the ~150 mechanical
 * hardcoded 'NAD'/'N$'/'NamRA' display literals this phase swept out of
 * Blade views into App\Support\Tenancy\TenantBranding's global view
 * composer (see AppServiceProvider::boot()), resolved per-request from
 * the viewing user's own organisation.
 *
 * Builds a second, wholly fictitious tax authority (country 'ZT', currency
 * 'ZTD'/'Z$', short name 'ZTRA') -- distinct from every field the DB-level
 * DEFAULTs themselves resolve to -- to prove branding genuinely varies per
 * tenant, not just a passthrough that happens to equal Namibia's own
 * values. Keeps a matching NamRA-tenant assertion on each page to prove
 * today's only real tenant sees zero visible change.
 */
class TenantBrandingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, ?string $taxAuthorityId = null): array
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
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /**
     * A second, fictitious tax authority whose currency symbol ('Z$') and
     * short name ('ZTRA') are deliberately distinct from every DB-level
     * DEFAULT ('N$'/'NamRA') -- proving a real value swap, not a
     * coincidental match. See JurisdictionAwareCalculationTest's own doc
     * comment on why DB::table()->insert() is used here, not Eloquent
     * create().
     */
    private function makeSecondTaxAuthority(): TaxAuthority
    {
        Country::create(['code' => 'ZT', 'iso3_code' => 'ZTS', 'name' => 'Zambesi Test', 'currency_code' => 'ZTD', 'currency_symbol' => 'Z$', 'status' => 'ACTIVE']);
        DB::table('tax_jurisdictions')->insert(['id' => 'tax-jurisdiction-zt-national', 'country_code' => 'ZT', 'code' => 'ZT-NATIONAL', 'name' => 'Zambesi Test national jurisdiction', 'status' => 'ACTIVE', 'created_at' => now()]);
        DB::table('tax_authorities')->insert(['id' => 'tax-authority-zt-test', 'jurisdiction_id' => 'tax-jurisdiction-zt-national', 'code' => 'ZTRA', 'short_name' => 'ZTRA', 'name' => 'Zambesi Test Revenue Authority', 'status' => 'ACTIVE', 'created_at' => now()]);

        return TaxAuthority::findOrFail('tax-authority-zt-test');
    }

    public function test_a_namra_tenants_dashboard_still_shows_nad_currency_symbols_exactly_as_before(): void
    {
        $party = $this->makeTradingParty('VAT-NA-DASH-0001');

        $response = $this->actingAs($party['owner'])->get('/dashboard');

        $response->assertOk()->assertSee('N$', false);
        $response->assertDontSee('Z$', false);
    }

    public function test_a_second_tenants_dashboard_shows_its_own_currency_symbol_not_nad(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        $party = $this->makeTradingParty('VAT-ZT-DASH-0001', $authority->id);

        $response = $this->actingAs($party['owner'])->get('/dashboard');

        $response->assertOk()->assertSee('Z$', false);
        $response->assertDontSee('N$', false);
    }

    public function test_a_namra_tenants_refunds_page_still_shows_the_namra_heading_exactly_as_before(): void
    {
        $party = $this->makeTradingParty('VAT-NA-RFND-0001');

        $response = $this->actingAs($party['owner'])->get('/refunds');

        $response->assertOk()->assertSee('NamRA VAT Summary Report');
    }

    public function test_a_second_tenants_refunds_page_shows_its_own_authority_name_not_namra(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        $party = $this->makeTradingParty('VAT-ZT-RFND-0001', $authority->id);

        $response = $this->actingAs($party['owner'])->get('/refunds');

        $response->assertOk()->assertSee('ZTRA VAT Summary Report');
        $response->assertDontSee('NamRA VAT Summary Report');
    }
}

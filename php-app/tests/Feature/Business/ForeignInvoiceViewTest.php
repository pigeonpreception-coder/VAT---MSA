<?php

namespace Tests\Feature\Business;

use App\Models\AuditEvent;
use App\Models\ImportRecord;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Foreign Invoices
 * (App\Http\Controllers\Business\ForeignInvoiceViewController /
 * resources/views/invoice-management/foreign.blade.php) -- the user's own
 * explicit request that foreign invoices be autonomously pulled from and
 * cross-authenticated against NamRA's E-Tariff border system. Since only
 * App\Integrations\Etariff\UnavailableEtariffAdapter is bound in this
 * environment (no real E-Tariff technical contract exists yet -- see that
 * adapter's own doc comment), every pull in this test suite is expected to
 * come back BLOCKED_CONFIGURATION with a friendly error, never a stack
 * trace and never a fabricated declaration.
 */
class ForeignInvoiceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, accountant: User, viewer: User} */
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
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@fiview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@fiview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Viewer", 'email' => strtolower($vatNumber).'-viewer@fiview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant', 'viewer');
    }

    public function test_the_foreign_invoices_page_requires_authentication(): void
    {
        $this->get('/invoice-management/foreign')->assertRedirect('/login');
    }

    public function test_a_role_without_imports_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0001');
        $outsider = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Imports Role', 'email' => 'noimports@fiview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($outsider)->get('/invoice-management/foreign')->assertForbidden();
    }

    public function test_the_page_renders_the_register_and_the_etariff_status_card(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0002');
        ImportRecord::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'declaration_number' => 'NAMCUS-2026-9001',
            'customs_office' => 'Walvis Bay', 'supplier_name' => 'Global Parts Ltd', 'country_of_origin' => 'ZA', 'currency' => 'NAD',
            'customs_value_cents' => 500000, 'import_vat_cents' => 75000, 'declaration_date' => now()->toDateString(),
            'status' => 'EVIDENCE_REQUIRED', 'created_by' => $org['owner']->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/invoice-management/foreign');

        $response->assertOk()->assertViewIs('invoice-management.foreign');
        $response->assertSee('NAMCUS-2026-9001');
        $response->assertSee('Global Parts Ltd');
        $response->assertSee('ZA');
        $response->assertSee('Not Configured');
        $response->assertSee('Pull from E-Tariff');
    }

    public function test_import_records_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0003');
        $otherOrg = $this->makeOrganisation('VAT-FI-0004');
        ImportRecord::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $otherOrg['organisation']->id, 'declaration_number' => 'NAMCUS-OTHER-0001',
            'customs_office' => 'Walvis Bay', 'supplier_name' => 'Other Org Supplier', 'country_of_origin' => 'ZA', 'currency' => 'NAD',
            'customs_value_cents' => 200000, 'import_vat_cents' => 30000, 'declaration_date' => now()->toDateString(),
            'status' => 'EVIDENCE_REQUIRED', 'created_by' => $otherOrg['owner']->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/invoice-management/foreign');

        $response->assertOk();
        $response->assertDontSee('NAMCUS-OTHER-0001');
        $response->assertDontSee('Other Org Supplier');
    }

    public function test_a_viewer_without_imports_manage_cannot_see_the_pull_button_or_pull(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0005');

        $response = $this->actingAs($org['viewer'])->get('/invoice-management/foreign');
        $response->assertOk();
        $response->assertDontSee('Pull from E-Tariff');

        $this->actingAs($org['viewer'])->post('/invoice-management/foreign/pull')->assertForbidden();
    }

    /**
     * RT-008 (2026-09-13 red-team pass): reproduced live -- three rapid
     * POSTs to /invoice-management/foreign/pull carrying the same
     * rendered form's idempotency key each wrote a distinct
     * FOREIGN_INVOICE_PULL_BLOCKED audit row. Harmless today only because
     * the integration is fully stubbed; once E-Tariff is real, a double-
     * click would fire the outbound call twice against a live government
     * system. Fixed with the same CommandLedger pattern as RT-007.
     */
    public function test_double_submitting_the_same_rendered_pull_form_writes_only_one_audit_entry(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0009');
        $key = (string) Str::uuid();

        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull', ['idempotency_key' => $key]);
        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull', ['idempotency_key' => $key]);
        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull', ['idempotency_key' => $key]);

        $this->assertSame(1, AuditEvent::where('action', 'FOREIGN_INVOICE_PULL_BLOCKED')->where('resource_id', $org['organisation']->id)->count());
    }

    public function test_a_fresh_pull_request_after_a_new_page_load_is_not_treated_as_a_replay(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0010');

        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull');
        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull');

        $this->assertSame(2, AuditEvent::where('action', 'FOREIGN_INVOICE_PULL_BLOCKED')->where('resource_id', $org['organisation']->id)->count());
    }

    public function test_pulling_from_an_unconfigured_etariff_shows_a_friendly_error_and_creates_no_records(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0006');

        $response = $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull');

        $response->assertRedirect('/invoice-management/foreign');
        $response->assertSessionHasErrors('pull');
        $this->assertSame(0, ImportRecord::where('organisation_id', $org['organisation']->id)->count());

        $follow = $this->actingAs($org['owner'])->get('/invoice-management/foreign');
        $follow->assertSee('awaiting a confirmed technical contract', false);
    }

    public function test_a_blocked_pull_does_not_alter_an_existing_import_record(): void
    {
        $org = $this->makeOrganisation('VAT-FI-0007');
        ImportRecord::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'declaration_number' => 'NAMCUS-2026-9002',
            'customs_office' => 'Walvis Bay', 'supplier_name' => 'Global Parts Ltd', 'country_of_origin' => 'ZA', 'currency' => 'NAD',
            'customs_value_cents' => 500000, 'import_vat_cents' => 75000, 'declaration_date' => now()->toDateString(),
            'status' => 'EVIDENCE_REQUIRED', 'source' => 'MANUAL', 'verification_status' => 'UNVERIFIED',
            'created_by' => $org['owner']->id, 'created_at' => now(),
        ]);

        $this->actingAs($org['owner'])->post('/invoice-management/foreign/pull');

        $this->assertDatabaseHas('import_records', [
            'declaration_number' => 'NAMCUS-2026-9002', 'source' => 'MANUAL', 'verification_status' => 'UNVERIFIED', 'pulled_at' => null,
        ]);
    }
}

<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\PartyRelationship;
use App\Models\Quotation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Customer Ledger
 * (App\Http\Controllers\Business\CustomerLedgerViewController /
 * resources/views/accounting/customer-ledger.blade.php) -- the route this
 * replaces was a $plannedRoute stub in routes/web.php until now, and the
 * receivables-side twin of tests/Feature/Business/SupplierLedgerViewTest.php.
 *
 * The real receivable-recognition event on this platform is
 * QuotationService::convertToInvoice() (Quotation.status ACCEPTED ->
 * CONVERTED, with a real certified Invoice created and linked via
 * converted_invoice_id) -- confirmed by reading that service directly, the
 * same "read what's really there, don't guess" approach the Supplier
 * Ledger took for Expense.supplier_party_id. These tests exercise
 * App\Services\Business\CustomerLedgerService reading real Quotation/
 * BusinessParty rows created directly (no QuotationLine rows are needed --
 * Quotation itself already stores subtotal_cents/tax_cents/total_cents).
 */
class CustomerLedgerViewTest extends TestCase
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
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@custledger.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeCustomer(Organisation $organisation, string $name): BusinessParty
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => $name, 'legal_name' => "{$name} Ltd",
            'vat_number' => 'VAT-'.Str::upper(Str::random(6)), 'source_system' => 'test', 'source_party_id' => Str::slug($name),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'CUSTOMER', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);

        return $party;
    }

    private function makeQuotation(Organisation $organisation, BusinessParty $customer, User $creator, array $overrides = []): Quotation
    {
        return Quotation::create(array_replace([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'customer_party_id' => $customer->id,
            'quotation_number' => 'QUO-'.Str::random(8), 'currency' => 'NAD', 'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(), 'status' => 'ACCEPTED', 'subtotal_cents' => 10000, 'tax_cents' => 1500,
            'total_cents' => 11500, 'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_the_customer_ledger_page_requires_authentication(): void
    {
        $this->get('/accounting/customer-ledger')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-CLDENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@custledger.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting/customer-ledger')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_with_no_customer_quotations(): void
    {
        $org = $this->makeOrganisation('VAT-CLEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/accounting/customer-ledger');

        $response->assertOk()->assertViewIs('accounting.customer-ledger');
        $response->assertSee('No customer quotations have been converted or accepted yet.');
        $response->assertSee('Select a customer above to view their full statement.');
    }

    public function test_it_totals_posted_and_pending_balances_per_customer_and_ranks_by_posted_total(): void
    {
        $org = $this->makeOrganisation('VAT-CLBAL-0001');
        $bigCustomer = $this->makeCustomer($org['organisation'], 'Big Corporate Client');
        $smallCustomer = $this->makeCustomer($org['organisation'], 'Small Retail Client');

        $this->makeQuotation($org['organisation'], $bigCustomer, $org['owner'], ['total_cents' => 500000, 'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid()]);
        $this->makeQuotation($org['organisation'], $bigCustomer, $org['owner'], ['total_cents' => 250000, 'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid()]);
        $this->makeQuotation($org['organisation'], $bigCustomer, $org['owner'], ['total_cents' => 90000, 'status' => 'ACCEPTED']);
        $this->makeQuotation($org['organisation'], $smallCustomer, $org['owner'], ['total_cents' => 5000, 'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid()]);
        // A DRAFT quotation is neither posted nor pending -- never recognised on the ledger at all.
        $this->makeQuotation($org['organisation'], $smallCustomer, $org['owner'], ['total_cents' => 999999, 'status' => 'DRAFT']);

        $response = $this->actingAs($org['owner'])->get('/accounting/customer-ledger');

        $response->assertOk();
        $response->assertSee('Big Corporate Client');
        $response->assertSee('N$ 7,500.00'); // big customer's posted balance (500000+250000 cents)
        $response->assertSee('N$ 900.00'); // big customer's pending amount
        $response->assertSee('Small Retail Client');
        $response->assertSee('N$ 50.00'); // small customer's posted balance
        $response->assertDontSee('9,999.99'); // the DRAFT quotation's amount never appears
        $response->assertSee('N$ 7,550.00'); // total posted balance across both customers
        $bigPos = strpos($response->getContent(), 'Big Corporate Client');
        $smallPos = strpos($response->getContent(), 'Small Retail Client');
        $this->assertLessThan($smallPos, $bigPos, 'The higher posted-balance customer should be ranked first.');
    }

    public function test_customer_balances_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-CLSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-CLSCOPE-0002');
        $otherCustomer = $this->makeCustomer($otherOrg['organisation'], 'Other Org Customer');
        $this->makeQuotation($otherOrg['organisation'], $otherCustomer, $otherOrg['owner'], ['total_cents' => 77700, 'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid()]);

        $response = $this->actingAs($org['owner'])->get('/accounting/customer-ledger');

        $response->assertOk();
        $response->assertDontSee('Other Org Customer');
        $response->assertDontSee('N$ 777.00');
    }

    public function test_a_customer_statement_shows_a_running_balance_that_only_converted_lines_move(): void
    {
        $org = $this->makeOrganisation('VAT-CLSTMT-0001');
        $customer = $this->makeCustomer($org['organisation'], 'Timber Exports Ltd');
        $this->makeQuotation($org['organisation'], $customer, $org['owner'], [
            'quotation_number' => 'QUO-STMT-1', 'issue_date' => '2026-05-01', 'total_cents' => 100000,
            'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid(),
        ]);
        $this->makeQuotation($org['organisation'], $customer, $org['owner'], [
            'quotation_number' => 'QUO-STMT-2', 'issue_date' => '2026-05-05', 'total_cents' => 50000, 'status' => 'REJECTED',
        ]);
        $this->makeQuotation($org['organisation'], $customer, $org['owner'], [
            'quotation_number' => 'QUO-STMT-3', 'issue_date' => '2026-05-10', 'total_cents' => 25000,
            'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/customer-ledger?customer_id='.$customer->id);

        $response->assertOk();
        $response->assertSee('Timber Exports Ltd');
        $response->assertSee('QUO-STMT-1');
        $response->assertSee('QUO-STMT-2');
        $response->assertSee('QUO-STMT-3');
        $response->assertSee('N$ 1,000.00'); // running balance after the first CONVERTED line
        $response->assertSee('N$ 1,250.00'); // running (and final) balance after the second CONVERTED line -- the REJECTED line never moved it
    }

    public function test_requesting_a_statement_for_a_customer_outside_the_organisation_404s(): void
    {
        $org = $this->makeOrganisation('VAT-CLFOREIGN-0001');
        $otherOrg = $this->makeOrganisation('VAT-CLFOREIGN-0002');
        $foreignCustomer = $this->makeCustomer($otherOrg['organisation'], 'Foreign Customer');

        $this->actingAs($org['owner'])->get('/accounting/customer-ledger?customer_id='.$foreignCustomer->id)->assertStatus(404);
    }

    public function test_the_customer_picker_only_lists_active_customers(): void
    {
        $org = $this->makeOrganisation('VAT-CLPICKER-0001');
        $activeCustomer = $this->makeCustomer($org['organisation'], 'Active Customer Co');
        $supplierOnly = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'display_name' => 'Supplier Only Co',
            'source_system' => 'test', 'source_party_id' => 'supplier-only', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'party_id' => $supplierOnly->id,
            'relationship' => 'SUPPLIER', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/customer-ledger');

        $response->assertOk();
        $response->assertSee('<option value="'.$activeCustomer->id.'"', false);
        $response->assertDontSee('<option value="'.$supplierOnly->id.'"', false);
    }
}

<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\PartyRelationship;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Supplier Ledger
 * (App\Http\Controllers\Business\SupplierLedgerViewController /
 * resources/views/accounting/supplier-ledger.blade.php) -- the route this
 * replaces was a $plannedRoute stub in routes/web.php until now.
 *
 * The placeholder's own copy promised balances "derived from the general
 * ledger", but nothing in this codebase posts a JournalEntry when an
 * expense is approved and journal_lines carries no supplier attribution at
 * all -- confirmed by reading both ExpenseService::approve and the
 * journal_lines migration directly. Confirmed with the user directly
 * (a clarifying question offering two designs) that this reads
 * Expense.supplier_party_id as-is rather than first wiring new GL side
 * effects into an already-shipped, already-tested approval flow -- so
 * these tests exercise App\Services\Business\SupplierLedgerService reading
 * real Expense/BusinessParty rows, not a journal-posting change.
 */
class SupplierLedgerViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@supledger.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeCategory(Organisation $organisation, string $code = 'GOODS'): ExpenseCategory
    {
        return ExpenseCategory::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => $code, 'name' => "Category {$code}",
            'default_tax_category' => 'STANDARD', 'requires_receipt' => true, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
    }

    private function makeSupplier(Organisation $organisation, string $name): BusinessParty
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => $name, 'legal_name' => "{$name} Ltd",
            'vat_number' => 'VAT-'.Str::upper(Str::random(6)), 'source_system' => 'test', 'source_party_id' => Str::slug($name),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'SUPPLIER', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);

        return $party;
    }

    private function makeExpense(Organisation $organisation, ExpenseCategory $category, ?BusinessParty $supplier, User $creator, array $overrides = []): Expense
    {
        return Expense::create(array_replace([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'category_id' => $category->id,
            'supplier_party_id' => $supplier?->id, 'expense_number' => 'EXP-'.Str::random(8), 'expense_date' => now()->toDateString(),
            'description' => 'Test expense', 'currency' => 'NAD', 'net_cents' => 10000, 'tax_cents' => 1500, 'total_cents' => 11500,
            'status' => 'APPROVED', 'created_by' => $creator->id, 'created_at' => now(), 'approved_by' => $creator->id, 'approved_at' => now(),
        ], $overrides));
    }

    public function test_the_supplier_ledger_page_requires_authentication(): void
    {
        $this->get('/accounting/supplier-ledger')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-SLDENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@supledger.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting/supplier-ledger')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_with_no_supplier_expenses(): void
    {
        $org = $this->makeOrganisation('VAT-SLEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger');

        $response->assertOk()->assertViewIs('accounting.supplier-ledger');
        $response->assertSee('No supplier expenses have been posted yet.');
        $response->assertSee('Select a supplier above to view their full statement.');
    }

    public function test_it_totals_posted_and_pending_balances_per_supplier_and_ranks_by_posted_total(): void
    {
        $org = $this->makeOrganisation('VAT-SLBAL-0001');
        $category = $this->makeCategory($org['organisation']);
        $bigSupplier = $this->makeSupplier($org['organisation'], 'Big Building Supplies');
        $smallSupplier = $this->makeSupplier($org['organisation'], 'Small Stationers');

        $this->makeExpense($org['organisation'], $category, $bigSupplier, $org['owner'], ['total_cents' => 500000, 'status' => 'APPROVED']);
        $this->makeExpense($org['organisation'], $category, $bigSupplier, $org['owner'], ['total_cents' => 250000, 'status' => 'APPROVED']);
        $this->makeExpense($org['organisation'], $category, $bigSupplier, $org['owner'], ['total_cents' => 90000, 'status' => 'SUBMITTED']);
        $this->makeExpense($org['organisation'], $category, $smallSupplier, $org['owner'], ['total_cents' => 5000, 'status' => 'APPROVED']);
        // A DRAFT expense is neither posted nor pending -- never recognised on the ledger at all.
        $this->makeExpense($org['organisation'], $category, $smallSupplier, $org['owner'], ['total_cents' => 999999, 'status' => 'DRAFT']);

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger');

        $response->assertOk();
        $response->assertSee('Big Building Supplies');
        $response->assertSee('N$ 7,500.00'); // big supplier's posted balance (500000+250000 cents)
        $response->assertSee('N$ 900.00'); // big supplier's pending amount
        $response->assertSee('Small Stationers');
        $response->assertSee('N$ 50.00'); // small supplier's posted balance
        $response->assertDontSee('9,999.99'); // the DRAFT expense's amount never appears
        $response->assertSee('N$ 7,550.00'); // total posted balance across both suppliers
        $bigPos = strpos($response->getContent(), 'Big Building Supplies');
        $smallPos = strpos($response->getContent(), 'Small Stationers');
        $this->assertLessThan($smallPos, $bigPos, 'The higher posted-balance supplier should be ranked first.');
    }

    public function test_expenses_with_no_supplier_recorded_are_shown_as_a_separate_unassigned_row(): void
    {
        $org = $this->makeOrganisation('VAT-SLUNASSIGNED-0001');
        $category = $this->makeCategory($org['organisation']);
        $this->makeExpense($org['organisation'], $category, null, $org['owner'], ['total_cents' => 12300, 'status' => 'APPROVED']);

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger');

        $response->assertOk();
        $response->assertSee('Unassigned (no supplier recorded)');
        $response->assertSee('N$ 123.00');
    }

    public function test_supplier_balances_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-SLSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-SLSCOPE-0002');
        $category = $this->makeCategory($otherOrg['organisation']);
        $otherSupplier = $this->makeSupplier($otherOrg['organisation'], 'Other Org Supplier');
        $this->makeExpense($otherOrg['organisation'], $category, $otherSupplier, $otherOrg['owner'], ['total_cents' => 77700, 'status' => 'APPROVED']);

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger');

        $response->assertOk();
        $response->assertDontSee('Other Org Supplier');
        $response->assertDontSee('N$ 777.00');
    }

    public function test_a_supplier_statement_shows_a_running_balance_that_only_approved_lines_move(): void
    {
        $org = $this->makeOrganisation('VAT-SLSTMT-0001');
        $category = $this->makeCategory($org['organisation']);
        $supplier = $this->makeSupplier($org['organisation'], 'Timber Merchants');
        $this->makeExpense($org['organisation'], $category, $supplier, $org['owner'], [
            'expense_number' => 'EXP-STMT-1', 'expense_date' => '2026-05-01', 'total_cents' => 100000, 'status' => 'APPROVED',
        ]);
        $this->makeExpense($org['organisation'], $category, $supplier, $org['owner'], [
            'expense_number' => 'EXP-STMT-2', 'expense_date' => '2026-05-05', 'total_cents' => 50000, 'status' => 'REJECTED', 'rejection_reason' => 'Duplicate claim',
        ]);
        $this->makeExpense($org['organisation'], $category, $supplier, $org['owner'], [
            'expense_number' => 'EXP-STMT-3', 'expense_date' => '2026-05-10', 'total_cents' => 25000, 'status' => 'APPROVED',
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger?supplier_id='.$supplier->id);

        $response->assertOk();
        $response->assertSee('Timber Merchants');
        $response->assertSee('EXP-STMT-1');
        $response->assertSee('EXP-STMT-2');
        $response->assertSee('EXP-STMT-3');
        $response->assertSee('N$ 1,000.00'); // running balance after the first APPROVED line
        $response->assertSee('N$ 1,250.00'); // running (and final) balance after the second APPROVED line -- the REJECTED line never moved it
    }

    public function test_requesting_a_statement_for_a_supplier_outside_the_organisation_404s(): void
    {
        $org = $this->makeOrganisation('VAT-SLFOREIGN-0001');
        $otherOrg = $this->makeOrganisation('VAT-SLFOREIGN-0002');
        $foreignSupplier = $this->makeSupplier($otherOrg['organisation'], 'Foreign Supplier');

        $this->actingAs($org['owner'])->get('/accounting/supplier-ledger?supplier_id='.$foreignSupplier->id)->assertStatus(404);
    }

    public function test_the_supplier_picker_only_lists_active_suppliers(): void
    {
        $org = $this->makeOrganisation('VAT-SLPICKER-0001');
        $activeSupplier = $this->makeSupplier($org['organisation'], 'Active Supplier Co');
        $customerOnly = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'display_name' => 'Customer Only Co',
            'source_system' => 'test', 'source_party_id' => 'customer-only', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'party_id' => $customerOnly->id,
            'relationship' => 'CUSTOMER', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/supplier-ledger');

        $response->assertOk();
        $response->assertSee('<option value="'.$activeSupplier->id.'"', false);
        $response->assertDontSee('<option value="'.$customerOnly->id.'"', false);
    }
}

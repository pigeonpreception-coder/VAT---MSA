<?php

namespace Tests\Feature\Business;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImportRecord;
use App\Models\InventoryBalance;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Product;
use App\Models\Project;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for business operations
 * (App\Http\Controllers\Business\OperationsViewController /
 * resources/views/operations/index.blade.php) -- ported from the source's
 * own app/operations/page.tsx + ExpenseDecisionActions.tsx +
 * ExpenseReceiptActions.tsx. Reuses App\Services\Business\ExpenseService
 * directly for every expense write (already covered end to end by
 * tests/Feature/Business/ExpenseTest.php), so this file's own job is the
 * access gate, the view's own rendering (including the enriched
 * category_name/supplier_name/receipt fields this slice added to
 * ExpenseService::present), the create-expense and submit actions this
 * slice adds to close the source's own dead end, and the maker-checker
 * decision flow's self-review denial as reached through this UI.
 */
class OperationsViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, accountant: User} */
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@opsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@opsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant');
    }

    private function makeCategory(Organisation $organisation, string $code = 'TRAVEL'): ExpenseCategory
    {
        return ExpenseCategory::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => $code, 'name' => "Category {$code}",
            'default_tax_category' => 'STANDARD', 'requires_receipt' => true, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
    }

    public function test_the_operations_page_requires_authentication(): void
    {
        $this->get('/operations')->assertRedirect('/login');
    }

    public function test_a_role_without_expenses_read_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-DENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@opsview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/operations')->assertForbidden();
    }

    public function test_the_operations_page_renders_all_four_panels(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0001');
        $category = $this->makeCategory($org['organisation']);
        Expense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'category_id' => $category->id,
            'expense_number' => 'EXP-0001', 'expense_date' => now()->toDateString(), 'description' => 'Fuel for delivery van',
            'currency' => 'NAD', 'net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'status' => 'DRAFT',
            'created_by' => $org['owner']->id, 'created_at' => now(),
        ]);
        $warehouse = Warehouse::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'code' => 'WH1', 'name' => 'Main Warehouse',
            'address' => '1 Depot Road', 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'sku' => 'SKU-001', 'name' => 'Packing Boxes',
            'unit_code' => 'EA', 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500, 'sales_price_cents' => 5000,
            'cost_price_cents' => 3000, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryBalance::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $product->id, 'quantity_micros' => 5_000_000, 'average_cost_cents' => 3000, 'version' => 1, 'updated_at' => now(),
        ]);
        Project::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'code' => 'PRJ-001', 'name' => 'Warehouse Fitout',
            'currency' => 'NAD', 'start_date' => now()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ImportRecord::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'declaration_number' => 'DECL-0001',
            'customs_office' => 'Walvis Bay', 'supplier_name' => 'Global Parts Ltd', 'country_of_origin' => 'ZA', 'currency' => 'NAD',
            'customs_value_cents' => 500000, 'import_vat_cents' => 75000, 'declaration_date' => now()->toDateString(),
            'status' => 'EVIDENCE_REQUIRED', 'created_by' => $org['owner']->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/operations');

        $response->assertOk()->assertViewIs('operations.index');
        $response->assertSee('EXP-0001');
        $response->assertSee('Fuel for delivery van');
        $response->assertSee('Category TRAVEL');
        $response->assertSee('Main Warehouse');
        $response->assertSee('Packing Boxes');
        $response->assertSee('PRJ-001');
        $response->assertSee('Warehouse Fitout');
        $response->assertSee('DECL-0001');
        $response->assertSee('Global Parts Ltd');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_import_records_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0002');
        $otherOrg = $this->makeOrganisation('VAT-SELLER-0003');
        ImportRecord::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $otherOrg['organisation']->id, 'declaration_number' => 'DECL-OTHER-0001',
            'customs_office' => 'Walvis Bay', 'supplier_name' => 'Other Org Supplier', 'country_of_origin' => 'ZA', 'currency' => 'NAD',
            'customs_value_cents' => 200000, 'import_vat_cents' => 30000, 'declaration_date' => now()->toDateString(),
            'status' => 'EVIDENCE_REQUIRED', 'created_by' => $otherOrg['owner']->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/operations');

        $response->assertOk();
        $response->assertDontSee('DECL-OTHER-0001');
        $response->assertDontSee('Other Org Supplier');
        $response->assertSee('No import declarations on record.');
    }

    public function test_an_expense_can_be_recorded_through_the_form(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0002');
        $category = $this->makeCategory($org['organisation']);

        $response = $this->actingAs($org['owner'])->post('/operations/expenses', [
            'expense_number' => 'EXP-FORM-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Client lunch meeting', 'net_cents' => 20000, 'tax_cents' => 3000,
        ]);

        $response->assertRedirect('/operations');
        $response->assertSessionHas('status', 'Expense recorded.');
        $this->assertDatabaseHas('expenses', ['expense_number' => 'EXP-FORM-0001', 'status' => 'DRAFT', 'total_cents' => 23000]);
    }

    public function test_a_role_without_expenses_manage_cannot_record_an_expense(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0003');
        $category = $this->makeCategory($org['organisation']);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'tpviewer@opsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->post('/operations/expenses', [
            'expense_number' => 'EXP-DENY-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Should be denied', 'net_cents' => 10000, 'tax_cents' => 1500,
        ])->assertForbidden();
    }

    public function test_a_draft_expense_can_be_submitted_then_approved_by_an_independent_reviewer(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0004');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/operations/expenses', [
            'expense_number' => 'EXP-FLOW-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Office supplies', 'net_cents' => 40000, 'tax_cents' => 6000,
        ]);
        $expenseId = Expense::where('expense_number', 'EXP-FLOW-0001')->firstOrFail()->id;

        $submit = $this->actingAs($org['owner'])->post("/operations/expenses/{$expenseId}/submission");
        $submit->assertSessionHas('status', 'Expense submitted for independent review.');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'SUBMITTED']);

        $approve = $this->actingAs($org['accountant'])->post("/operations/expenses/{$expenseId}/approval");
        $approve->assertSessionHas('status', 'Expense approved.');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'APPROVED']);
    }

    public function test_the_creator_cannot_approve_their_own_submitted_expense(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0005');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/operations/expenses', [
            'expense_number' => 'EXP-SELF-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Self review attempt', 'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $expenseId = Expense::where('expense_number', 'EXP-SELF-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/operations/expenses/{$expenseId}/submission");

        $this->actingAs($org['owner'])->post("/operations/expenses/{$expenseId}/approval")->assertForbidden();
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'SUBMITTED']);
    }

    public function test_a_submitted_expense_can_be_rejected_with_a_reason(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0006');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/operations/expenses', [
            'expense_number' => 'EXP-REJ-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Questionable claim', 'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $expenseId = Expense::where('expense_number', 'EXP-REJ-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/operations/expenses/{$expenseId}/submission");

        $response = $this->actingAs($org['accountant'])->post("/operations/expenses/{$expenseId}/rejection", ['reason' => 'Missing an itemised receipt.']);

        $response->assertSessionHas('status', 'Expense rejected.');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'REJECTED']);
    }

    /**
     * Red-team finding RT-001 (VAT-MSA Resilience Audit, 2026-09-09):
     * expense_number carries a real DB-level unique constraint but had no
     * application-level pre-check ahead of it -- a plain sequential
     * resubmission (no concurrency needed) crashed with an uncaught 500
     * showing the raw SQLSTATE[23000] error, confirmed live against a
     * running dev instance. Two independent layers now cover this: the
     * new pre-check in ExpenseService::create() (this test), and
     * bootstrap/app.php's own QueryException handler as a last-resort
     * backstop for any duplicate-key violation this or any other pre-check
     * misses.
     */
    public function test_resubmitting_an_expense_number_that_already_exists_shows_a_friendly_error_not_a_500(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0007');
        $category = $this->makeCategory($org['organisation']);
        $payload = [
            'expense_number' => 'EXP-DUP-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'First submission', 'net_cents' => 10000, 'tax_cents' => 1500,
        ];
        $this->actingAs($org['owner'])->post('/operations/expenses', $payload)
            ->assertSessionHas('status', 'Expense recorded.');

        $response = $this->actingAs($org['owner'])->post('/operations/expenses', array_merge($payload, ['description' => 'Second, distinct submission attempt']));

        $response->assertStatus(302)->assertSessionHasErrors('expense');
        $this->assertSame(1, Expense::where('expense_number', 'EXP-DUP-0001')->count());
    }

    /**
     * Red-team finding RT-003 (VAT-MSA Resilience Audit, 2026-09-09): the
     * Blade UI layer generated a fresh idempotency key on every request,
     * silently defeating App\Support\Business\CommandLedger::prior()'s own
     * replay detection for every one of 11 controllers, this one included.
     * A double-click (or a browser-back-then-resubmit) on the real "record
     * expense" form now carries the same key both times -- the second
     * request should be recognised as a replay of the first, returning the
     * same expense rather than either creating a second row or falling
     * through to the RT-001 duplicate-number error path.
     */
    public function test_resubmitting_the_same_rendered_form_via_its_own_idempotency_key_is_a_safe_replay_not_a_duplicate(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0008');
        $category = $this->makeCategory($org['organisation']);
        $payload = [
            'expense_number' => 'EXP-REPLAY-0001', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'description' => 'Double-click replay test', 'net_cents' => 10000, 'tax_cents' => 1500,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($org['owner'])->post('/operations/expenses', $payload)
            ->assertSessionHas('status', 'Expense recorded.');
        $this->actingAs($org['owner'])->post('/operations/expenses', $payload)
            ->assertSessionHas('status', 'Expense recorded.');

        $this->assertSame(1, Expense::where('expense_number', 'EXP-REPLAY-0001')->count());
    }
}

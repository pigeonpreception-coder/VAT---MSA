<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\PartyRelationship;
use App\Models\PurchaseOrder;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Purchase Orders page
 * (App\Http\Controllers\Business\PurchaseOrderViewController /
 * resources/views/accounting/purchase-orders.blade.php) -- the route this
 * replaces was a $plannedRoute stub in routes/web.php until now. Unlike
 * every other placeholder closed in this window, a full-repo grep before
 * writing a line of this feature confirmed the placeholder's own "no
 * purchase-order domain model exists" scope note was accurate -- this is
 * a genuinely new domain (App\Models\PurchaseOrder,
 * App\Services\Business\PurchaseOrderService), not a UI-only gap over
 * existing data.
 *
 * Lifecycle exercised end to end through the real Blade routes: DRAFT ->
 * SUBMITTED -> APPROVED -> ISSUED -> CONVERTED (a real Expense created via
 * ExpenseService::create(), never a second write path), plus the
 * REJECTED and CANCELLED terminal alternates and the maker-checker
 * self-review guard.
 */
class PurchaseOrderViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@poview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@poview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant');
    }

    private function makeSupplier(Organisation $organisation, string $name): BusinessParty
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => $name,
            'source_system' => 'test', 'source_party_id' => Str::slug($name), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'SUPPLIER', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now()->subDay(),
        ]);

        return $party;
    }

    private function makeCategory(Organisation $organisation, string $code = 'GOODS'): ExpenseCategory
    {
        return ExpenseCategory::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => $code, 'name' => "Category {$code}",
            'default_tax_category' => 'STANDARD', 'requires_receipt' => false, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
    }

    public function test_the_purchase_orders_page_requires_authentication(): void
    {
        $this->get('/accounting/purchase-orders')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-PODENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@poview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting/purchase-orders')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_with_no_purchase_orders(): void
    {
        $org = $this->makeOrganisation('VAT-POEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/accounting/purchase-orders');

        $response->assertOk()->assertViewIs('accounting.purchase-orders');
        $response->assertSee('No purchase orders on record.');
    }

    public function test_a_purchase_order_can_be_created_through_the_form(): void
    {
        $org = $this->makeOrganisation('VAT-POCREATE-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Windhoek Building Supplies');
        $category = $this->makeCategory($org['organisation']);

        $response = $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Cement and rebar', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 100000, 'tax_cents' => 15000,
        ]);

        $response->assertRedirect('/accounting/purchase-orders');
        $response->assertSessionHas('status', 'Purchase order created.');
        $this->assertDatabaseHas('purchase_orders', ['po_number' => 'PO-0001', 'status' => 'DRAFT', 'total_cents' => 115000]);
    }

    public function test_a_role_without_accounting_post_cannot_create_a_purchase_order(): void
    {
        $org = $this->makeOrganisation('VAT-PONOMANAGE-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Denied Supplier');
        $category = $this->makeCategory($org['organisation']);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'nopost@poview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->post('/accounting/purchase-orders', [
            'po_number' => 'PO-DENY-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Should be denied', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 10000, 'tax_cents' => 1500,
        ])->assertForbidden();
    }

    public function test_the_full_lifecycle_from_draft_to_a_converted_expense(): void
    {
        $org = $this->makeOrganisation('VAT-POFLOW-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Full Flow Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-FLOW-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Office furniture', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 200000, 'tax_cents' => 30000,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-FLOW-0001')->firstOrFail()->id;

        $submit = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/submission");
        $submit->assertSessionHas('status', 'Purchase order submitted for approval.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'SUBMITTED']);

        $approve = $this->actingAs($org['accountant'])->post("/accounting/purchase-orders/{$orderId}/approval");
        $approve->assertSessionHas('status', 'Purchase order approved.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'APPROVED']);

        $issue = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/issuance");
        $issue->assertSessionHas('status', 'Purchase order issued to the supplier.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'ISSUED']);

        $convert = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/conversion", ['expense_number' => 'EXP-FROM-PO-0001']);
        $convert->assertSessionHas('status', 'Purchase order converted to a supplier expense.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'CONVERTED']);
        $this->assertDatabaseHas('expenses', [
            'expense_number' => 'EXP-FROM-PO-0001', 'organisation_id' => $org['organisation']->id,
            'supplier_party_id' => $supplier->id, 'total_cents' => 230000,
        ]);
        $order = PurchaseOrder::find($orderId);
        $expense = Expense::where('expense_number', 'EXP-FROM-PO-0001')->firstOrFail();
        $this->assertSame($expense->id, $order->converted_expense_id);
    }

    /**
     * Broader security-sweep follow-up (2026-09-20): convertToExpense() was
     * the one status-transition method in PurchaseOrderService missing the
     * affected-row check every sibling method (submit/approve/reject/
     * issue/cancel) already has, and it also creates a real Expense row
     * before any guard runs. Two concurrent conversions of the same ISSUED
     * order (different idempotency keys -- two tabs, not a same-key
     * retry/replay) could both pass the pre-check, both create their own
     * real Expense, and the loser's unguarded update would silently no-op
     * while still reporting success -- leaving a second, real, orphaned
     * Expense with no PO reference and no error ever surfaced. Fixed by
     * wrapping the lock, the expense creation, and the now-guarded update
     * in one transaction: `lockForUpdate()` serializes a concurrent
     * attempt behind this one, so it re-reads a status that can no longer
     * be ISSUED and never reaches ExpenseService::create() at all.
     * Reproduced the same way as InvoiceLifecycleTest's own credit-note
     * race test: a `DB::listen()` hook simulates the concurrent winner's
     * already-committed conversion landing the instant this request's own
     * lock-acquiring SELECT fires.
     */
    public function test_converting_a_purchase_order_that_races_a_concurrent_conversion_does_not_create_an_orphaned_duplicate_expense(): void
    {
        $org = $this->makeOrganisation('VAT-PORACE-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Race Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-RACE-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Raced conversion', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 200000, 'tax_cents' => 30000,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-RACE-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/submission");
        $this->actingAs($org['accountant'])->post("/accounting/purchase-orders/{$orderId}/approval");
        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/issuance");

        $raced = false;
        DB::listen(function ($query) use (&$raced, $orderId) {
            if ($raced || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $raced = true;
            // Models the concurrent winner's own conversion, already fully
            // committed (a real Expense row of its own, not modelled here
            // since it isn't what this request's own code reads) by the
            // time this request's lock-acquiring SELECT runs.
            DB::table('purchase_orders')->where('id', $orderId)->update([
                'status' => 'CONVERTED', 'converted_expense_id' => (string) Str::uuid(), 'updated_at' => now(),
            ]);
        });

        $convert = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/conversion", ['expense_number' => 'EXP-RACE-LOSER-0001']);

        $this->assertTrue($raced, 'The DB::listen() hook must have fired to simulate the race (it never fires at all on the pre-fix code path, which has no lockForUpdate query).');
        $convert->assertSessionHasErrors('purchase_order');
        // The would-be loser's own Expense must never have been created --
        // pre-fix, it would exist here as a real, orphaned row despite the
        // request appearing to fail.
        $this->assertDatabaseMissing('expenses', ['expense_number' => 'EXP-RACE-LOSER-0001']);
    }

    public function test_the_creator_cannot_approve_their_own_submitted_order(): void
    {
        $org = $this->makeOrganisation('VAT-POSELF-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Self Review Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-SELF-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Self review attempt', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-SELF-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/submission");

        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/approval")->assertForbidden();
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'SUBMITTED']);
    }

    public function test_a_submitted_order_can_be_rejected_with_a_reason(): void
    {
        $org = $this->makeOrganisation('VAT-POREJ-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Rejected Order Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-REJ-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Questionable order', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-REJ-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/submission");

        $response = $this->actingAs($org['accountant'])->post("/accounting/purchase-orders/{$orderId}/rejection", ['reason' => 'Budget not available this quarter.']);

        $response->assertSessionHas('status', 'Purchase order rejected.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'REJECTED']);
    }

    public function test_an_approved_order_can_be_cancelled_instead_of_issued(): void
    {
        $org = $this->makeOrganisation('VAT-POCANCEL-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Cancelled Order Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-CANCEL-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Will be cancelled', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-CANCEL-0001')->firstOrFail()->id;
        $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/submission");
        $this->actingAs($org['accountant'])->post("/accounting/purchase-orders/{$orderId}/approval");

        $response = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/cancellation", ['reason' => 'Supplier went out of business.']);

        $response->assertSessionHas('status', 'Purchase order cancelled.');
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'CANCELLED']);
    }

    public function test_converting_a_purchase_order_that_is_not_yet_issued_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-POBADCONVERT-0001');
        $supplier = $this->makeSupplier($org['organisation'], 'Not Issued Supplier');
        $category = $this->makeCategory($org['organisation']);
        $this->actingAs($org['owner'])->post('/accounting/purchase-orders', [
            'po_number' => 'PO-NOISSUE-0001', 'supplier_party_id' => $supplier->id, 'category_id' => $category->id,
            'description' => 'Still a draft', 'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'net_cents' => 10000, 'tax_cents' => 1500,
        ]);
        $orderId = PurchaseOrder::where('po_number', 'PO-NOISSUE-0001')->firstOrFail()->id;

        $response = $this->actingAs($org['owner'])->post("/accounting/purchase-orders/{$orderId}/conversion", ['expense_number' => 'EXP-SHOULD-NOT-EXIST']);

        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'DRAFT']);
        $this->assertDatabaseMissing('expenses', ['expense_number' => 'EXP-SHOULD-NOT-EXIST']);
    }

    public function test_purchase_orders_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-POSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-POSCOPE-0002');
        $otherSupplier = $this->makeSupplier($otherOrg['organisation'], 'Other Org Supplier');
        $otherCategory = $this->makeCategory($otherOrg['organisation']);
        PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $otherOrg['organisation']->id, 'supplier_party_id' => $otherSupplier->id,
            'category_id' => $otherCategory->id, 'po_number' => 'PO-OTHER-0001', 'currency' => 'NAD', 'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(), 'status' => 'DRAFT', 'description' => 'Other org order',
            'net_cents' => 10000, 'tax_cents' => 1500, 'total_cents' => 11500, 'created_by' => $otherOrg['owner']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/purchase-orders');

        $response->assertOk();
        $response->assertDontSee('PO-OTHER-0001');
        $response->assertDontSee('Other org order');
    }
}

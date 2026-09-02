<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 10 (slice 3): expenses (App\Services\Business\ExpenseService,
 * ported from createExpenseCategory/createExpense/submitExpense/
 * approveExpense/rejectExpense/getExpenseReport) -- Module 5 Phase E, and
 * its maker-checker separation (an expense's creator can never approve or
 * reject it themselves).
 */
class ExpenseTest extends TestCase
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return array_merge(compact('taxpayer', 'organisation', 'owner'), ['accountant' => $accountant]);
    }

    private function createCategory(User $owner, string $code = 'TRAVEL'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Category {$code}", 'default_tax_category' => 'STANDARD', 'requires_receipt' => true,
        ], ['Idempotency-Key' => 'test-idem-cat-'.$code]);

        return $response->json('resource.id');
    }

    private function expensePayload(string $categoryId, array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'expense_number' => 'EXP-TEST-0001',
            'expense_date' => '2026-09-01', 'description' => 'Client travel expense', 'currency' => 'NAD',
            'net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
        ], $overrides);
    }

    public function test_an_expense_category_can_be_created_and_a_duplicate_code_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0001');

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => 'TRAVEL', 'name' => 'Travel', 'default_tax_category' => 'STANDARD',
        ], ['Idempotency-Key' => 'test-idem-cat-travel-0001']);
        $response->assertStatus(201)->assertJsonPath('resource.code', 'TRAVEL')->assertJsonPath('resource.requires_receipt', true);

        $duplicate = $this->actingAs($org['owner'])->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => 'TRAVEL', 'name' => 'Travel Again', 'default_tax_category' => 'STANDARD',
        ], ['Idempotency-Key' => 'test-idem-cat-travel-0002']);
        $duplicate->assertStatus(409);
    }

    public function test_an_expense_with_mismatched_totals_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0002');
        $categoryId = $this->createCategory($org['owner']);

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($categoryId, ['total_cents' => 999999]), ['Idempotency-Key' => 'test-idem-exp-badtotal-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'TOTAL_MISMATCH');
    }

    public function test_the_full_draft_submit_approve_lifecycle_requires_a_different_reviewer(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0003');
        $categoryId = $this->createCategory($org['owner']);

        $create = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($categoryId), ['Idempotency-Key' => 'test-idem-exp-create-0001']);
        $create->assertStatus(201)->assertJsonPath('resource.status', 'DRAFT');
        $expenseId = $create->json('resource.id');

        $submit = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/submission", [], ['Idempotency-Key' => 'test-idem-exp-submit-0001']);
        $submit->assertStatus(200)->assertJsonPath('resource.status', 'SUBMITTED');

        // Maker-checker: the creator cannot approve their own expense.
        $selfApprove = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/approval", [], ['Idempotency-Key' => 'test-idem-exp-selfapprove-0001']);
        $selfApprove->assertStatus(403);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'SUBMITTED']);

        $approve = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/approval", [], ['Idempotency-Key' => 'test-idem-exp-approve-0001']);
        $approve->assertStatus(200)->assertJsonPath('resource.status', 'APPROVED');
        $this->assertDatabaseHas('audit_events', ['action' => 'EXPENSE_APPROVED', 'resource_id' => $expenseId]);

        // An already-approved expense cannot be approved again.
        $reapprove = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/approval", [], ['Idempotency-Key' => 'test-idem-exp-reapprove-0001']);
        $reapprove->assertStatus(409);
    }

    public function test_a_submitted_expense_can_be_rejected_by_a_different_reviewer(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0004');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($categoryId), ['Idempotency-Key' => 'test-idem-exp-create-0002'])->json('resource.id');
        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/submission", [], ['Idempotency-Key' => 'test-idem-exp-submit-0002'])->assertStatus(200);

        $selfReject = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/rejection", ['schema_version' => '1.0.0', 'reason' => 'Trying to reject my own expense.'], ['Idempotency-Key' => 'test-idem-exp-selfreject-0001']);
        $selfReject->assertStatus(403);

        $reject = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/rejection", ['schema_version' => '1.0.0', 'reason' => 'Missing a valid receipt.'], ['Idempotency-Key' => 'test-idem-exp-reject-0001']);
        $reject->assertStatus(200)->assertJsonPath('resource.status', 'REJECTED')->assertJsonPath('resource.rejection_reason', 'Missing a valid receipt.');
    }

    public function test_an_expense_report_totals_by_status_and_category(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0005');
        $categoryId = $this->createCategory($org['owner']);
        $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($categoryId), ['Idempotency-Key' => 'test-idem-exp-report-0001'])->assertStatus(201);

        $response = $this->actingAs($org['owner'])->getJson('/api/v1/expenses/report?from=2026-09-01&to=2026-09-30');

        $response->assertStatus(200)->assertJsonPath('total_cents', 115000)->assertJsonPath('by_status.0.status', 'DRAFT')->assertJsonCount(1, 'items');
    }

    public function test_a_viewer_without_expenses_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-EXP-0006');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-exp@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => 'TRAVEL', 'name' => 'Travel', 'default_tax_category' => 'STANDARD',
        ], ['Idempotency-Key' => 'test-idem-cat-viewer-0001']);

        $response->assertStatus(403);
    }
}

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
 * Covers Phase 10 (slice 5, final): projects (App\Services\Business\
 * ProjectService, ported from createProject/approveProjectBudget/
 * postProjectCost/getProjectProfitability) -- Module 5 Phase E. This
 * closes out lib/data/business-repository.ts entirely except
 * verifySupplier.
 */
class ProjectTest extends TestCase
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        // TAXPAYER_ACCOUNTANT holds projects:read but not projects:manage (see
        // Permissions::ROLE_PERMISSIONS) -- budget approval needs a second
        // projects:manage holder distinct from the project's own manager (the
        // owner, who created it), so this uses TAXPAYER_ADMIN instead.
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return array_merge(compact('taxpayer', 'organisation', 'owner'), ['accountant' => $accountant, 'admin' => $admin]);
    }

    private function createProject(User $owner, string $code = 'PROJ-0001', array $overrides = []): string
    {
        $payload = array_replace([
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Project {$code}", 'currency' => 'NAD',
            'start_date' => '2026-09-01', 'budget_cents' => 500000,
        ], $overrides);
        $response = $this->actingAs($owner)->postJson('/api/v1/projects', $payload, ['Idempotency-Key' => 'test-idem-proj-'.$code]);

        return $response->json('resource.id');
    }

    public function test_a_project_can_be_created_with_a_proposed_budget(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0001');

        $response = $this->actingAs($org['owner'])->postJson('/api/v1/projects', [
            'schema_version' => '1.0.0', 'code' => 'PROJ-0001', 'name' => 'Office Renovation', 'currency' => 'NAD',
            'start_date' => '2026-09-01', 'budget_cents' => 500000,
        ], ['Idempotency-Key' => 'test-idem-proj-create-0001']);

        $response->assertStatus(201)->assertJsonPath('resource.status', 'PLANNED')->assertJsonPath('resource.budget.status', 'PROPOSED')->assertJsonPath('resource.budget.amount_cents', 500000);
        $this->assertDatabaseHas('projects', ['code' => 'PROJ-0001', 'status' => 'PLANNED']);
        $this->assertDatabaseHas('project_budgets', ['category' => 'TOTAL', 'status' => 'PROPOSED', 'amount_cents' => 500000]);
    }

    public function test_budget_approval_requires_a_different_manager_and_records_the_approved_amount(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0002');
        $projectId = $this->createProject($org['owner']);

        // The project's own manager (the creator, per CreateProject) cannot approve their own budget.
        $selfApprove = $this->actingAs($org['owner'])->postJson("/api/v1/projects/{$projectId}/budget-approval", [
            'schema_version' => '1.0.0', 'approved_amount_cents' => 450000,
        ], ['Idempotency-Key' => 'test-idem-proj-selfapprove-0001']);
        $selfApprove->assertStatus(403);

        $approve = $this->actingAs($org['admin'])->postJson("/api/v1/projects/{$projectId}/budget-approval", [
            'schema_version' => '1.0.0', 'approved_amount_cents' => 450000, 'notes' => 'Approved at a reduced amount.',
        ], ['Idempotency-Key' => 'test-idem-proj-approve-0001']);
        $approve->assertStatus(200)->assertJsonPath('resource.status', 'APPROVED')->assertJsonPath('resource.approved_amount_cents', 450000);
        $this->assertDatabaseHas('audit_events', ['action' => 'PROJECT_BUDGET_APPROVED']);
    }

    public function test_an_approved_expense_can_be_posted_as_a_project_cost_but_not_twice(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0003');
        $projectId = $this->createProject($org['owner']);

        $category = $this->actingAs($org['owner'])->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => 'MATERIALS', 'name' => 'Materials', 'default_tax_category' => 'STANDARD',
        ], ['Idempotency-Key' => 'test-idem-proj-cat-0001'])->json('resource.id');
        $expenseId = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', [
            'schema_version' => '1.0.0', 'category_id' => $category, 'project_id' => $projectId, 'expense_number' => 'EXP-PROJ-0001',
            'expense_date' => '2026-09-05', 'description' => 'Materials for the project.', 'currency' => 'NAD',
            'net_cents' => 40000, 'tax_cents' => 6000, 'total_cents' => 46000,
        ], ['Idempotency-Key' => 'test-idem-proj-exp-0001'])->json('resource.id');
        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/submission", [], ['Idempotency-Key' => 'test-idem-proj-exp-submit-0001'])->assertStatus(200);
        $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/approval", [], ['Idempotency-Key' => 'test-idem-proj-exp-approve-0001'])->assertStatus(200);

        $cost = $this->actingAs($org['owner'])->postJson("/api/v1/projects/{$projectId}/costs", [
            'schema_version' => '1.0.0', 'cost_type' => 'EXPENSE', 'source_id' => $expenseId,
        ], ['Idempotency-Key' => 'test-idem-proj-cost-0001']);
        $cost->assertStatus(201)->assertJsonPath('resource.amount_cents', 46000)->assertJsonPath('resource.cost_type', 'EXPENSE');

        // The same expense cannot be posted as a project cost a second time.
        $duplicateCost = $this->actingAs($org['owner'])->postJson("/api/v1/projects/{$projectId}/costs", [
            'schema_version' => '1.0.0', 'cost_type' => 'EXPENSE', 'source_id' => $expenseId,
        ], ['Idempotency-Key' => 'test-idem-proj-cost-0002']);
        $duplicateCost->assertStatus(409);
    }

    public function test_a_manual_cost_can_be_posted_directly(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0004');
        $projectId = $this->createProject($org['owner']);

        $cost = $this->actingAs($org['owner'])->postJson("/api/v1/projects/{$projectId}/costs", [
            'schema_version' => '1.0.0', 'cost_type' => 'MANUAL', 'source_id' => 'external-invoice-001',
            'amount_cents' => 20000, 'currency' => 'NAD', 'description' => 'External contractor invoice.', 'occurred_at' => '2026-09-10',
        ], ['Idempotency-Key' => 'test-idem-proj-manualcost-0001']);

        $cost->assertStatus(201)->assertJsonPath('resource.amount_cents', 20000)->assertJsonPath('resource.cost_type', 'MANUAL');
    }

    public function test_profitability_reflects_costs_and_revenue(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0005');
        $projectId = $this->createProject($org['owner']);
        $this->actingAs($org['owner'])->postJson("/api/v1/projects/{$projectId}/costs", [
            'schema_version' => '1.0.0', 'cost_type' => 'MANUAL', 'source_id' => 'external-invoice-002',
            'amount_cents' => 30000, 'currency' => 'NAD', 'description' => 'Contractor invoice.', 'occurred_at' => '2026-09-10',
        ], ['Idempotency-Key' => 'test-idem-proj-profit-cost-0001'])->assertStatus(201);

        $bank = $this->actingAs($org['owner'])->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => 'BANK', 'name' => 'Bank', 'account_type' => 'ASSET', 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-proj-profit-acct1-0001'])->json('resource.id');
        $revenue = $this->actingAs($org['owner'])->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => 'REV', 'name' => 'Revenue', 'account_type' => 'REVENUE', 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-proj-profit-acct2-0001'])->json('resource.id');
        $this->actingAs($org['owner'])->postJson('/api/v1/accounting/journals', [
            'schema_version' => '1.0.0', 'journal_number' => 'JRN-PROJ-0001', 'journal_date' => '2026-09-01',
            'description' => 'Project revenue posting.', 'currency' => 'NAD', 'source_type' => 'MANUAL',
            'lines' => [
                ['account_id' => $bank, 'description' => 'Cash received', 'debit_cents' => 80000, 'credit_cents' => 0],
                ['account_id' => $revenue, 'project_id' => $projectId, 'description' => 'Project revenue', 'debit_cents' => 0, 'credit_cents' => 80000],
            ],
        ], ['Idempotency-Key' => 'test-idem-proj-profit-jrn-0001'])->assertStatus(201);

        $response = $this->actingAs($org['owner'])->getJson("/api/v1/projects/{$projectId}/profitability");

        $response->assertStatus(200)->assertJsonPath('cost_cents', 30000)->assertJsonPath('revenue_cents', 80000)->assertJsonPath('profit_cents', 50000);
    }

    public function test_a_viewer_without_projects_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-PROJ-0006');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-proj@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/projects', [
            'schema_version' => '1.0.0', 'code' => 'PROJ-VIEWER', 'name' => 'Viewer Project', 'currency' => 'NAD', 'start_date' => '2026-09-01',
        ], ['Idempotency-Key' => 'test-idem-proj-viewer-0001']);

        $response->assertStatus(403);
    }
}

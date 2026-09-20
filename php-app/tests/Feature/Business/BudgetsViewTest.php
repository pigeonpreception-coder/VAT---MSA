<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Budgets page
 * (App\Http\Controllers\Business\BudgetsViewController /
 * resources/views/accounting/budgets.blade.php) -- the route this
 * replaces was a $plannedRoute stub in routes/web.php until now, whose own
 * scope note ("No budget domain model exists in the platform today") was
 * confirmed stale by reading App\Services\Business\ProjectService
 * directly: App\Models\ProjectBudget/ProjectCost and
 * ProjectService::approveBudget already existed and are already covered
 * end to end by tests/Feature/Business/ProjectTest.php -- what was
 * actually missing was any Blade UI action to reach approveBudget at all
 * (confirmed via a full-repo grep before writing a line of this page).
 * These tests reuse that real service for the approval action (never a
 * second write path) and Eloquent-create Project/ProjectBudget/ProjectCost
 * rows directly for the read-side assertions, matching the precedent
 * OperationsViewTest/SupplierLedgerViewTest already establish.
 */
class BudgetsViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@budgetview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeProject(Organisation $organisation, User $manager, array $overrides = []): Project
    {
        return Project::create(array_replace([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'PRJ-'.Str::random(6), 'name' => 'Test Project',
            'manager_user_id' => $manager->id, 'currency' => 'NAD', 'start_date' => now()->toDateString(), 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function makeBudget(Project $project, array $overrides = []): ProjectBudget
    {
        return ProjectBudget::create(array_replace([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'category' => 'TOTAL', 'amount_cents' => 100000,
            'approved_amount_cents' => 0, 'status' => 'PROPOSED', 'created_at' => now(),
        ], $overrides));
    }

    public function test_the_budgets_page_requires_authentication(): void
    {
        $this->get('/accounting/budgets')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-BVDENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@budgetview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting/budgets')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_with_no_projects(): void
    {
        $org = $this->makeOrganisation('VAT-BVEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/accounting/budgets');

        $response->assertOk()->assertViewIs('accounting.budgets');
        $response->assertSee('No projects on record.');
    }

    public function test_it_shows_proposed_approved_cost_and_variance_per_project_and_totals(): void
    {
        $org = $this->makeOrganisation('VAT-BVTOTALS-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Warehouse Fitout']);
        $this->makeBudget($project, ['amount_cents' => 500000, 'approved_amount_cents' => 450000, 'status' => 'APPROVED']);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-1',
            'amount_cents' => 300000, 'currency' => 'NAD', 'occurred_at' => now()->toDateString(), 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/budgets');

        $response->assertOk();
        $response->assertSee('Warehouse Fitout');
        $response->assertSee('N$ 5,000.00'); // proposed
        $response->assertSee('N$ 4,500.00'); // approved, and total approved (only project)
        $response->assertSee('NAD 3,000.00'); // actual cost
        $response->assertSee('N$ 1,500.00'); // variance (4500 - 3000)
    }

    public function test_a_proposed_budget_with_no_activity_shows_no_budget_proposed(): void
    {
        $org = $this->makeOrganisation('VAT-BVNOBUDGET-0001');
        $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Unbudgeted Project']);

        $response = $this->actingAs($org['owner'])->get('/accounting/budgets');

        $response->assertOk();
        $response->assertSee('Unbudgeted Project');
        $response->assertSee('No budget proposed');
    }

    public function test_projects_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-BVSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-BVSCOPE-0002');
        $this->makeProject($otherOrg['organisation'], $otherOrg['owner'], ['name' => 'Other Org Project']);

        $response = $this->actingAs($org['owner'])->get('/accounting/budgets');

        $response->assertOk();
        $response->assertDontSee('Other Org Project');
    }

    public function test_an_independent_approver_can_approve_a_proposed_budget(): void
    {
        $org = $this->makeOrganisation('VAT-BVAPPROVE-0001');
        $approver = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Independent Approver', 'email' => 'approver@budgetview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $project = $this->makeProject($org['organisation'], $org['owner']);
        $this->makeBudget($project, ['amount_cents' => 200000]);

        $response = $this->actingAs($approver)->post(route('accounting.budgets.approval', $project->id), ['approved_amount_cents' => 180000]);

        $response->assertRedirect(route('accounting.budgets'));
        $response->assertSessionHas('status', 'Project budget approved.');
        $this->assertDatabaseHas('project_budgets', ['project_id' => $project->id, 'status' => 'APPROVED', 'approved_amount_cents' => 180000, 'approved_by' => $approver->id]);
    }

    public function test_the_projects_own_manager_cannot_approve_its_own_budget(): void
    {
        $org = $this->makeOrganisation('VAT-BVSELF-0001');
        $project = $this->makeProject($org['organisation'], $org['owner']);
        $this->makeBudget($project, ['amount_cents' => 200000]);

        $this->actingAs($org['owner'])->post(route('accounting.budgets.approval', $project->id), ['approved_amount_cents' => 180000])
            ->assertForbidden();
        $this->assertDatabaseHas('project_budgets', ['project_id' => $project->id, 'status' => 'PROPOSED']);
    }

    public function test_a_role_without_projects_manage_cannot_approve_a_budget(): void
    {
        $org = $this->makeOrganisation('VAT-BVNOMANAGE-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'noaccess@budgetview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $project = $this->makeProject($org['organisation'], $org['owner']);
        $this->makeBudget($project, ['amount_cents' => 200000]);

        $this->actingAs($viewer)->post(route('accounting.budgets.approval', $project->id), ['approved_amount_cents' => 180000])
            ->assertForbidden();
        $this->assertDatabaseHas('project_budgets', ['project_id' => $project->id, 'status' => 'PROPOSED']);
    }

    public function test_a_non_numeric_approved_amount_is_rejected_not_silently_zeroed(): void
    {
        $org = $this->makeOrganisation('VAT-BVNAN-0001');
        $approver = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Independent Approver', 'email' => 'nan-approver@budgetview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $project = $this->makeProject($org['organisation'], $org['owner']);
        $this->makeBudget($project, ['amount_cents' => 200000]);

        $response = $this->actingAs($approver)->post(route('accounting.budgets.approval', $project->id), ['approved_amount_cents' => 'not-a-number']);

        $response->assertRedirect(route('accounting.budgets'));
        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('project_budgets', ['project_id' => $project->id, 'status' => 'PROPOSED']);
    }
}

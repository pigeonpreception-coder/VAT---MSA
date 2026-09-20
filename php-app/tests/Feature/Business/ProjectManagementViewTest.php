<?php

namespace Tests\Feature\Business;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the three Project Management sidebar links
 * (App\Http\Controllers\Business\ProjectManagementViewController /
 * resources/views/project-management/{new,ongoing,completed}.blade.php) --
 * each replaces a $plannedRoute stub in routes/web.php whose own scope note
 * ("No dedicated project domain model exists") was confirmed stale, the
 * same class of gap Budgets/Cash Flow Projects closed. Also covers the one
 * genuine addition this page needed: ProjectService::activate()/complete(),
 * since nothing in the ported service ever moved a project off 'PLANNED'
 * (see that service's own doc comments) -- those transitions are already
 * covered end to end at the service/JSON-API level by
 * tests/Feature/Business/ProjectTest.php, so this file's own job is the
 * access gates and the three Blade pages themselves.
 */
class ProjectManagementViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@pmview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeProject(Organisation $organisation, User $manager, array $overrides = []): Project
    {
        return Project::create(array_replace([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'PRJ-'.Str::random(6), 'name' => 'Test Project',
            'manager_user_id' => $manager->id, 'currency' => 'NAD', 'start_date' => now()->toDateString(), 'status' => 'PLANNED',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function postRevenue(Organisation $organisation, Project $project, User $actor, int $amountCents = 0): void
    {
        $account = ChartOfAccount::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'REV-'.Str::random(4), 'name' => 'Project revenue',
            'account_type' => 'REVENUE', 'currency' => 'NAD', 'control_type' => null, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $entry = JournalEntry::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'journal_number' => 'JRN-'.Str::random(6),
            'journal_date' => now()->toDateString(), 'reference' => null, 'description' => 'Project revenue', 'currency' => 'NAD',
            'status' => 'POSTED', 'source_type' => 'MANUAL', 'source_id' => null, 'created_by' => $actor->id, 'posted_by' => $actor->id,
            'created_at' => now(), 'posted_at' => now(),
        ]);
        JournalLine::create([
            'id' => (string) Str::uuid(), 'journal_entry_id' => $entry->id, 'line_number' => 1, 'account_id' => $account->id,
            'project_id' => $project->id, 'description' => 'Revenue', 'debit_cents' => 0, 'credit_cents' => $amountCents,
        ]);
    }

    // -- access gates --

    public function test_the_new_project_page_requires_authentication(): void
    {
        $this->get('/project-management/new')->assertRedirect('/login');
    }

    public function test_a_role_without_projects_read_is_denied_on_all_three_pages(): void
    {
        $org = $this->makeOrganisation('VAT-PMDENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Access', 'email' => 'viewer@pmview.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/project-management/new')->assertForbidden();
        $this->actingAs($viewer)->get('/project-management/ongoing')->assertForbidden();
        $this->actingAs($viewer)->get('/project-management/completed')->assertForbidden();
    }

    // -- Create New Project --

    public function test_the_new_project_page_shows_planned_projects_and_the_create_form(): void
    {
        $org = $this->makeOrganisation('VAT-PMNEW-0001');
        $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Riverside Development', 'code' => 'PRJ-RIVER']);

        $response = $this->actingAs($org['owner'])->get('/project-management/new');

        $response->assertOk()->assertViewIs('project-management.new');
        $response->assertSee('Riverside Development');
        $response->assertSee('PRJ-RIVER');
        $response->assertSee('Create project');
        $response->assertSee('Activate');
    }

    public function test_a_project_can_be_created_through_the_form(): void
    {
        $org = $this->makeOrganisation('VAT-PMCREATE-0001');

        $response = $this->actingAs($org['owner'])->post('/project-management', [
            'code' => 'PRJ-FORM-0001', 'name' => 'Warehouse Expansion', 'start_date' => '2026-09-01', 'budget_cents' => 500000,
        ]);

        $response->assertRedirect('/project-management/new');
        $response->assertSessionHas('status', 'Project created.');
        $this->assertDatabaseHas('projects', ['code' => 'PRJ-FORM-0001', 'name' => 'Warehouse Expansion', 'status' => 'PLANNED']);
        $this->assertDatabaseHas('project_budgets', ['category' => 'TOTAL', 'status' => 'PROPOSED', 'amount_cents' => 500000]);
    }

    public function test_a_role_without_projects_manage_cannot_create_a_project(): void
    {
        $org = $this->makeOrganisation('VAT-PMCREATEDENY-0001');
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Accountant', 'email' => 'accountant@pmview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($accountant)->post('/project-management', [
            'code' => 'PRJ-DENY-0001', 'name' => 'Denied Project', 'start_date' => '2026-09-01',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('projects', ['code' => 'PRJ-DENY-0001']);
    }

    public function test_activating_a_planned_project_moves_it_to_the_ongoing_page(): void
    {
        $org = $this->makeOrganisation('VAT-PMACTIVATE-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Activation Target']);

        $activate = $this->actingAs($org['owner'])->post("/project-management/{$project->id}/activation");

        $activate->assertRedirect('/project-management/ongoing');
        $activate->assertSessionHas('status', 'Project activated.');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'ACTIVE']);

        $newPage = $this->actingAs($org['owner'])->get('/project-management/new');
        $newPage->assertDontSee('Activation Target');

        $ongoingPage = $this->actingAs($org['owner'])->get('/project-management/ongoing');
        $ongoingPage->assertSee('Activation Target');
    }

    // -- Ongoing Project Reports --

    public function test_the_ongoing_page_shows_an_empty_state_with_no_active_projects(): void
    {
        $org = $this->makeOrganisation('VAT-PMONGOINGEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/project-management/ongoing');

        $response->assertOk()->assertViewIs('project-management.ongoing');
        $response->assertSee('No active projects on record.');
    }

    public function test_the_ongoing_page_computes_budget_revenue_cost_and_profit(): void
    {
        $org = $this->makeOrganisation('VAT-PMONGOING-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Ongoing Mall Build', 'status' => 'ACTIVE']);
        ProjectBudget::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'category' => 'TOTAL', 'amount_cents' => 1000000,
            'approved_amount_cents' => 900000, 'status' => 'APPROVED', 'created_at' => now(),
        ]);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-1',
            'amount_cents' => 400000, 'currency' => 'NAD', 'occurred_at' => now()->toDateString(), 'created_at' => now(),
        ]);
        $this->postRevenue($org['organisation'], $project, $org['owner'], 700000);

        $response = $this->actingAs($org['owner'])->get('/project-management/ongoing');

        $response->assertOk();
        $response->assertSee('Ongoing Mall Build');
        $response->assertSee('N$ 9,000.00'); // approved budget
        $response->assertSee('N$ 7,000.00'); // revenue
        $response->assertSee('N$ 4,000.00'); // cost
        $response->assertSee('N$ 3,000.00'); // profit
        $response->assertSee('Mark completed');
    }

    public function test_a_planned_project_does_not_appear_on_the_ongoing_page(): void
    {
        $org = $this->makeOrganisation('VAT-PMONGOINGSCOPE-0001');
        $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Still Planned']);

        $response = $this->actingAs($org['owner'])->get('/project-management/ongoing');

        $response->assertOk();
        $response->assertDontSee('Still Planned');
    }

    public function test_completing_an_active_project_moves_it_to_the_completed_page(): void
    {
        $org = $this->makeOrganisation('VAT-PMCOMPLETE-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Completion Target', 'status' => 'ACTIVE']);

        $complete = $this->actingAs($org['owner'])->post("/project-management/{$project->id}/completion");

        $complete->assertRedirect('/project-management/completed');
        $complete->assertSessionHas('status', 'Project marked completed.');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'COMPLETED']);

        $ongoingPage = $this->actingAs($org['owner'])->get('/project-management/ongoing');
        $ongoingPage->assertDontSee('Completion Target');

        $completedPage = $this->actingAs($org['owner'])->get('/project-management/completed');
        $completedPage->assertSee('Completion Target');
    }

    // -- Completed Projects --

    public function test_the_completed_page_shows_an_empty_state_with_no_completed_projects(): void
    {
        $org = $this->makeOrganisation('VAT-PMCOMPLETEDEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/project-management/completed');

        $response->assertOk()->assertViewIs('project-management.completed');
        $response->assertSee('No completed projects on record.');
    }

    public function test_the_completed_page_is_read_only_with_no_action_buttons(): void
    {
        $org = $this->makeOrganisation('VAT-PMCOMPLETEDREADONLY-0001');
        $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Finished Development', 'status' => 'COMPLETED']);

        $response = $this->actingAs($org['owner'])->get('/project-management/completed');

        $response->assertOk();
        $response->assertSee('Finished Development');
        $response->assertDontSee('Mark completed');
        $response->assertDontSee('Activate');
    }

    public function test_projects_are_scoped_to_the_organisation_on_all_three_pages(): void
    {
        $org = $this->makeOrganisation('VAT-PMSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-PMSCOPE-0002');
        $this->makeProject($otherOrg['organisation'], $otherOrg['owner'], ['name' => 'Other Org Planned']);
        $this->makeProject($otherOrg['organisation'], $otherOrg['owner'], ['name' => 'Other Org Active', 'status' => 'ACTIVE']);
        $this->makeProject($otherOrg['organisation'], $otherOrg['owner'], ['name' => 'Other Org Completed', 'status' => 'COMPLETED']);

        $this->actingAs($org['owner'])->get('/project-management/new')->assertDontSee('Other Org Planned');
        $this->actingAs($org['owner'])->get('/project-management/ongoing')->assertDontSee('Other Org Active');
        $this->actingAs($org['owner'])->get('/project-management/completed')->assertDontSee('Other Org Completed');
    }

    /**
     * Backlog item #10's follow-up (docs/LAUNCH_READINESS_BACKLOG.md,
     * 2026-09-20): presentProjects()'s own doc comment claims the same
     * fixed-query-count batching Budgets/Cash Flow already established --
     * checked directly at a volume unmistakable enough that an unfixed
     * N+1 (one extra query per project) can't be missed.
     */
    public function test_the_ongoing_page_query_count_does_not_scale_with_project_count(): void
    {
        $org = $this->makeOrganisation('VAT-PMNPLUS1-0001');
        for ($i = 0; $i < 40; $i++) {
            $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => "Project {$i}", 'status' => 'ACTIVE']);
            ProjectBudget::create([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'category' => 'TOTAL', 'amount_cents' => 1000000,
                'approved_amount_cents' => 900000, 'status' => 'APPROVED', 'created_at' => now(),
            ]);
            ProjectCost::create([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => "cost-{$i}",
                'amount_cents' => 400000, 'currency' => 'NAD', 'occurred_at' => now()->toDateString(), 'created_at' => now(),
            ]);
            $this->postRevenue($org['organisation'], $project, $org['owner'], 700000);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($org['owner'])->get('/project-management/ongoing');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(15, $queryCount, "Expected a small, row-count-independent query count; got {$queryCount} for 40 projects -- an N+1 regression scales with row count, not a fixed ceiling.");
    }
}

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
 * Covers the real Blade UI for the Cash Flow Projects page
 * (App\Http\Controllers\Business\CashFlowViewController /
 * resources/views/accounting/cash-flow.blade.php) -- the route this
 * replaces was a $plannedRoute stub in routes/web.php until now, whose own
 * scope note ("Project Management does not yet have a dedicated project
 * domain model") was confirmed stale by reading
 * App\Services\Business\ProjectService::profitability() directly: it
 * already computes real project revenue (REVENUE-type journal_lines
 * tagged to the project)/cost (ProjectCost)/budget (ProjectBudget), and a
 * full-repo grep confirmed no Blade UI anywhere ever reached it.
 */
class CashFlowViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@cashflowview.test',
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

    public function test_the_cash_flow_page_requires_authentication(): void
    {
        $this->get('/accounting/cash-flow')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-CFDENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@cashflowview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting/cash-flow')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_with_no_projects(): void
    {
        $org = $this->makeOrganisation('VAT-CFEMPTY-0001');

        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow');

        $response->assertOk()->assertViewIs('accounting.cash-flow');
        $response->assertSee('No projects on record.');
    }

    public function test_it_computes_revenue_cost_and_net_cash_flow_per_project_and_totals(): void
    {
        $org = $this->makeOrganisation('VAT-CFTOTALS-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Riverside Development']);
        ProjectBudget::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'category' => 'TOTAL', 'amount_cents' => 1000000,
            'approved_amount_cents' => 900000, 'status' => 'APPROVED', 'created_at' => now(),
        ]);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-1',
            'amount_cents' => 400000, 'currency' => 'NAD', 'occurred_at' => now()->toDateString(), 'created_at' => now(),
        ]);
        $this->postRevenue($org['organisation'], $project, $org['owner'], 700000);

        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow');

        $response->assertOk();
        $response->assertSee('Riverside Development');
        $response->assertSee('NAD 9,000.00'); // approved budget
        $response->assertSee('NAD 7,000.00'); // revenue, twice (row + total)
        $response->assertSee('NAD 4,000.00'); // cost
        $response->assertSee('NAD 3,000.00'); // net cash flow (7000 - 4000)
    }

    public function test_a_project_with_more_cost_than_revenue_shows_a_negative_net_cash_flow(): void
    {
        $org = $this->makeOrganisation('VAT-CFNEGATIVE-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Overrun Project']);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-1',
            'amount_cents' => 500000, 'currency' => 'NAD', 'occurred_at' => now()->toDateString(), 'created_at' => now(),
        ]);
        $this->postRevenue($org['organisation'], $project, $org['owner'], 100000);

        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow');

        $response->assertOk();
        $response->assertSee('Overrun Project');
        $response->assertSee('NAD -4,000.00');
    }

    public function test_projects_are_scoped_to_the_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-CFSCOPE-0001');
        $otherOrg = $this->makeOrganisation('VAT-CFSCOPE-0002');
        $this->makeProject($otherOrg['organisation'], $otherOrg['owner'], ['name' => 'Other Org Project']);

        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow');

        $response->assertOk();
        $response->assertDontSee('Other Org Project');
    }

    public function test_selecting_a_project_shows_its_own_cost_timeline_with_a_cumulative_total(): void
    {
        $org = $this->makeOrganisation('VAT-CFTIMELINE-0001');
        $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => 'Timeline Project']);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-1',
            'amount_cents' => 100000, 'currency' => 'NAD', 'description' => 'Foundation work', 'occurred_at' => '2026-05-01', 'created_at' => now(),
        ]);
        ProjectCost::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'cost_type' => 'MANUAL', 'source_id' => 'cost-2',
            'amount_cents' => 50000, 'currency' => 'NAD', 'description' => 'Roofing', 'occurred_at' => '2026-05-10', 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow?project_id='.$project->id);

        $response->assertOk();
        $response->assertSee('Timeline Project');
        $response->assertSee('Foundation work');
        $response->assertSee('Roofing');
        $response->assertSee('NAD 1,500.00'); // cumulative total after both lines
    }

    /**
     * Backlog item #10's follow-up (docs/LAUNCH_READINESS_BACKLOG.md,
     * 2026-09-20): this controller's own doc comment claims its revenue/
     * cost/budget batching stays a fixed number of queries regardless of
     * project count -- checked directly at a volume unmistakable enough
     * that an unfixed N+1 (one extra query per project) can't be missed.
     */
    public function test_the_index_query_count_does_not_scale_with_project_count(): void
    {
        $org = $this->makeOrganisation('VAT-CFNPLUS1-0001');
        for ($i = 0; $i < 40; $i++) {
            $project = $this->makeProject($org['organisation'], $org['owner'], ['name' => "Project {$i}"]);
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
        $response = $this->actingAs($org['owner'])->get('/accounting/cash-flow');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(15, $queryCount, "Expected a small, row-count-independent query count; got {$queryCount} for 40 projects -- an N+1 regression scales with row count, not a fixed ceiling.");
    }
}

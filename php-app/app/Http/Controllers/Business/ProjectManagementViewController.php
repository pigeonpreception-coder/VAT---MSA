<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Services\Business\BusinessPartyService;
use App\Services\Business\ProjectService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Serves the three Project Management sidebar links (project-management.new
 * /.ongoing/.completed), each a $plannedRoute stub in routes/web.php until
 * now (removed; route names and projects:read permission kept identical so
 * the sidebar's Project Management group needed no change). All three
 * placeholders claimed "No dedicated project domain model exists in the
 * platform today" -- stale, the same class of gap Budgets/Cash Flow Projects
 * closed: App\Models\Project/ProjectBudget/ProjectCost and
 * App\Services\Business\ProjectService::create() already exist, already
 * validated, already exercised end to end by
 * tests/Feature/Business/ProjectTest.php, with no Blade UI reaching them.
 *
 * One genuine gap this controller's own actions close (not just a missing
 * view): the ported ProjectService::create() always leaves a project at
 * 'PLANNED' and nothing in the source ever moved it further, so "Ongoing"
 * and "Completed" were structurally unreachable -- see
 * ProjectService::activate()/complete()'s own doc comments.
 *
 * The placeholder's own proposed field list for Create New Project
 * ("description, location, ... expected revenue, category, VAT treatment")
 * is wider than the ported `projects` table/BusinessValidator::project()
 * actually carry (code, name, customer, currency, dates, budget, manager).
 * This form captures exactly what the real domain model supports, not the
 * placeholder's aspirational superset -- the same call BudgetsViewController
 * made rather than inventing columns nothing else reads.
 */
class ProjectManagementViewController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly BusinessPartyService $parties,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function newProject(Request $request): View
    {
        $this->authorize('permission', 'projects:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));
        $partiesSnapshot = $this->parties->search($user, $organisation->id, []);

        $planned = Project::where('organisation_id', $organisation->id)->where('status', 'PLANNED')
            ->with('customer')->orderByDesc('start_date')->limit(200)->get();

        return view('project-management.new', [
            'planned' => $planned,
            'customers' => collect($partiesSnapshot['parties'])->filter(fn ($p) => $p['status'] === 'ACTIVE' && in_array('CUSTOMER', $p['relationships'], true))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'projects:manage');
        $payload = [
            'schema_version' => '1.0.0', 'code' => $request->input('code'), 'name' => $request->input('name'),
            'customer_party_id' => $request->input('customer_party_id') ?: null, 'currency' => 'NAD',
            'start_date' => $request->input('start_date'), 'end_date' => $request->input('end_date') ?: null,
            'budget_cents' => $this->safeIntegerInput($request->input('budget_cents')) ?: null,
        ];

        try {
            $this->projects->create($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('project-management.new')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('project-management.new')->withErrors(['project' => $e->getMessage()])->withInput();
        }

        return redirect()->route('project-management.new')->with('status', 'Project created.');
    }

    public function activate(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'projects:manage');

        try {
            $this->projects->activate($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('project-management.new')->withErrors(['project' => $e->getMessage()]);
        }

        return redirect()->route('project-management.ongoing')->with('status', 'Project activated.');
    }

    public function ongoing(Request $request): View
    {
        $this->authorize('permission', 'projects:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $projects = $this->presentProjects($organisation->id, 'ACTIVE');

        return view('project-management.ongoing', ['projects' => $projects]);
    }

    public function complete(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'projects:manage');

        try {
            $this->projects->complete($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('project-management.ongoing')->withErrors(['project' => $e->getMessage()]);
        }

        return redirect()->route('project-management.completed')->with('status', 'Project marked completed.');
    }

    public function completed(Request $request): View
    {
        $this->authorize('permission', 'projects:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $projects = $this->presentProjects($organisation->id, 'COMPLETED');

        return view('project-management.completed', ['projects' => $projects]);
    }

    /**
     * Same batched revenue/cost/budget read BudgetsViewController/
     * CashFlowViewController's own index already use -- three
     * organisation-wide queries regardless of row count, never
     * ProjectService::profitability() called once per row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentProjects(string $organisationId, string $status): array
    {
        $projectModels = Project::where('organisation_id', $organisationId)->where('status', $status)
            ->with('customer')->orderByDesc('start_date')->limit(200)->get();
        $projectIds = $projectModels->pluck('id');

        $budgetsByProject = $projectIds->isEmpty() ? collect() : ProjectBudget::whereIn('project_id', $projectIds)->where('category', 'TOTAL')->get()->keyBy('project_id');
        $costsByProject = $projectIds->isEmpty() ? collect() : ProjectCost::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(amount_cents) as total')->groupBy('project_id')->get()->keyBy('project_id');
        $revenueByProject = $projectIds->isEmpty() ? collect() : DB::table('journal_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_lines.project_id', $projectIds)->where('chart_of_accounts.account_type', 'REVENUE')
            ->selectRaw('journal_lines.project_id, COALESCE(SUM(journal_lines.credit_cents),0) - COALESCE(SUM(journal_lines.debit_cents),0) as net_cents')
            ->groupBy('journal_lines.project_id')->get()->keyBy('project_id');

        return $projectModels->map(function (Project $project) use ($budgetsByProject, $costsByProject, $revenueByProject) {
            $budget = $budgetsByProject->get($project->id);
            $costCents = (int) optional($costsByProject->get($project->id))->total;
            $revenueCents = (int) optional($revenueByProject->get($project->id))->net_cents;
            $approvedBudgetCents = $budget && $budget->status === 'APPROVED' ? (int) $budget->approved_amount_cents : 0;

            return [
                'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
                'customer_name' => optional($project->customer)->display_name, 'currency' => $project->currency,
                'start_date' => $project->start_date->toDateString(), 'end_date' => optional($project->end_date)->toDateString(),
                'approved_budget_cents' => $approvedBudgetCents, 'revenue_cents' => $revenueCents, 'cost_cents' => $costCents,
                'profit_cents' => $revenueCents - $costCents,
            ];
        })->values()->all();
    }
}

<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Serves accounting.cash-flow (/accounting/cash-flow), a route that was a
 * $plannedRoute stub in routes/web.php until now (removed; route name and
 * accounting:read permission kept identical so the sidebar's Accounting &
 * Finance > Cash Flow Projects link needed no change). The placeholder's
 * own scope note ("Project Management does not yet have a dedicated
 * project domain model to derive cash flow from") was confirmed stale by
 * reading App\Services\Business\ProjectService directly: its own
 * profitability() method already computes exactly this (revenue via
 * REVENUE-type journal_lines tagged to the project, cost via ProjectCost,
 * budget via ProjectBudget) and is already exercised end to end by
 * tests/Feature/Business/ProjectTest.php -- confirmed by a full-repo grep
 * that `ProjectController::profitability` (the JSON API) had no Blade UI
 * action reaching it at all, the same class of gap Budgets closed.
 *
 * This index deliberately batches the same three reads profitability()
 * makes per-project (revenue/cost/budget) into three organisation-wide
 * queries instead of calling that method once per project -- the same
 * N+1-avoidance shape BudgetsViewController's own index already
 * established, since profitability()'s own single-project shape doesn't
 * fit a list view. "Forecasting" (the placeholder's own word) is not
 * attempted -- there is no projection data anywhere in this platform to
 * forecast from, so this page monitors real posted cash flow to date, not
 * a fabricated projection.
 */
class CashFlowViewController extends Controller
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $projectModels = Project::where('organisation_id', $organisation->id)->with('customer')->orderByDesc('start_date')->limit(200)->get();
        $projectIds = $projectModels->pluck('id');

        $budgetsByProject = $projectIds->isEmpty() ? collect() : ProjectBudget::whereIn('project_id', $projectIds)->where('category', 'TOTAL')->get()->keyBy('project_id');
        $costsByProject = $projectIds->isEmpty() ? collect() : ProjectCost::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(amount_cents) as total')->groupBy('project_id')->get()->keyBy('project_id');
        $revenueByProject = $projectIds->isEmpty() ? collect() : DB::table('journal_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_lines.project_id', $projectIds)->where('chart_of_accounts.account_type', 'REVENUE')
            ->selectRaw('journal_lines.project_id, COALESCE(SUM(journal_lines.credit_cents),0) - COALESCE(SUM(journal_lines.debit_cents),0) as net_cents')
            ->groupBy('journal_lines.project_id')->get()->keyBy('project_id');

        $projects = $projectModels->map(function (Project $project) use ($budgetsByProject, $costsByProject, $revenueByProject) {
            $budget = $budgetsByProject->get($project->id);
            $costCents = (int) optional($costsByProject->get($project->id))->total;
            $revenueCents = (int) optional($revenueByProject->get($project->id))->net_cents;
            $approvedBudgetCents = $budget && $budget->status === 'APPROVED' ? (int) $budget->approved_amount_cents : 0;

            return [
                'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
                'customer_name' => optional($project->customer)->display_name, 'currency' => $project->currency, 'status' => $project->status,
                'approved_budget_cents' => $approvedBudgetCents, 'revenue_cents' => $revenueCents, 'cost_cents' => $costCents,
                'net_cash_flow_cents' => $revenueCents - $costCents,
            ];
        });

        $totals = [
            'approved_budget_cents' => (int) $projects->sum('approved_budget_cents'), 'revenue_cents' => (int) $projects->sum('revenue_cents'),
            'cost_cents' => (int) $projects->sum('cost_cents'), 'net_cash_flow_cents' => (int) $projects->sum('net_cash_flow_cents'),
        ];

        $selectedProjectId = $request->query('project_id');
        $timeline = null;
        if ($selectedProjectId) {
            $selectedProject = $projectModels->firstWhere('id', $selectedProjectId);
            if ($selectedProject) {
                $costs = ProjectCost::where('project_id', $selectedProjectId)->orderBy('occurred_at')->orderBy('created_at')->get();
                $running = 0;
                $timeline = [
                    'project' => ['id' => $selectedProject->id, 'code' => $selectedProject->code, 'name' => $selectedProject->name, 'currency' => $selectedProject->currency],
                    'lines' => $costs->map(function (ProjectCost $cost) use (&$running) {
                        $running += (int) $cost->amount_cents;

                        return [
                            'occurred_at' => $cost->occurred_at->toDateString(), 'cost_type' => $cost->cost_type, 'description' => $cost->description,
                            'amount_cents' => (int) $cost->amount_cents, 'cumulative_cost_cents' => $running,
                        ];
                    })->values()->all(),
                ];
            }
        }

        return view('accounting.cash-flow', ['projects' => $projects, 'totals' => $totals, 'timeline' => $timeline, 'selectedProjectId' => $selectedProjectId]);
    }
}

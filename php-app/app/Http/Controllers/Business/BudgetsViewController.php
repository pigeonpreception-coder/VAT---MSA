<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Services\Business\ProjectService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Serves accounting.budgets (/accounting/budgets), a route that was a
 * $plannedRoute stub in routes/web.php until now (removed; route name and
 * accounting:read permission kept identical so the sidebar's Accounting &
 * Finance > Budgets link needed no change). The placeholder's own scope
 * note claimed "No budget domain model exists in the platform today" --
 * true when originally written, but stale: App\Models\ProjectBudget/
 * ProjectCost and App\Services\Business\ProjectService::approveBudget
 * already exist and are already exercised end to end by
 * tests/Feature/Business/ProjectTest.php, confirmed by reading that
 * service directly. What was actually missing was a Blade UI for it --
 * operations/index.blade.php's own "Project control" panel already shows
 * each project's approved budget vs cost (read-only, no organisation-wide
 * totals), but ProjectController::approveBudget had no UI action anywhere
 * to actually approve a PROPOSED budget; this page adds both an
 * organisation-wide budget-vs-actual summary and that missing approval
 * action, reusing ProjectService::approveBudget directly (never a second
 * write path) so its own maker-checker self-review guard, idempotency and
 * audit trail all apply exactly as they already do via the JSON API.
 */
class BudgetsViewController extends Controller
{
    public function __construct(private readonly ProjectService $projects, private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'accounting:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));

        $projectModels = Project::where('organisation_id', $organisation->id)->with('customer')->orderByDesc('start_date')->limit(200)->get();
        $projectIds = $projectModels->pluck('id');
        $budgetsByProject = $projectIds->isEmpty() ? collect() : ProjectBudget::whereIn('project_id', $projectIds)->where('category', 'TOTAL')->get()->keyBy('project_id');
        $costsByProject = $projectIds->isEmpty() ? collect() : ProjectCost::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(amount_cents) as total')->groupBy('project_id')->get()->keyBy('project_id');

        $projects = $projectModels->map(function (Project $project) use ($budgetsByProject, $costsByProject) {
            $budget = $budgetsByProject->get($project->id);
            $costCents = (int) optional($costsByProject->get($project->id))->total;
            $approvedCents = $budget && $budget->status === 'APPROVED' ? (int) $budget->approved_amount_cents : 0;

            return [
                'id' => $project->id, 'code' => $project->code, 'name' => $project->name,
                'customer_name' => optional($project->customer)->display_name, 'currency' => $project->currency, 'status' => $project->status,
                'budget_id' => $budget?->id, 'budget_status' => $budget?->status, 'proposed_cents' => (int) ($budget->amount_cents ?? 0),
                'approved_cents' => $approvedCents, 'cost_cents' => $costCents, 'variance_cents' => $approvedCents - $costCents,
            ];
        });

        $totals = [
            'proposed_cents' => (int) $projects->sum('proposed_cents'), 'approved_cents' => (int) $projects->sum('approved_cents'),
            'cost_cents' => (int) $projects->sum('cost_cents'), 'variance_cents' => (int) $projects->sum('variance_cents'),
        ];

        return view('accounting.budgets', ['projects' => $projects, 'totals' => $totals]);
    }

    public function approve(Request $request, string $projectId): RedirectResponse
    {
        $this->authorize('permission', 'projects:manage');
        $payload = [
            'schema_version' => '1.0.0', 'approved_amount_cents' => $this->safeIntegerInput($request->input('approved_amount_cents')),
            'notes' => $request->input('notes') ?: null,
        ];

        try {
            $this->projects->approveBudget($projectId, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('accounting.budgets')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('accounting.budgets')->withErrors(['budget' => $e->getMessage()]);
        }

        return redirect()->route('accounting.budgets')->with('status', 'Project budget approved.');
    }
}

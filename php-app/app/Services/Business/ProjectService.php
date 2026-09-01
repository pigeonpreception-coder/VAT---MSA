<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectCost;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's createProject/
 * approveProjectBudget/postProjectCost/getProjectProfitability -- Module 5
 * Phase E, the fifth and final Phase 10 slice. Closes out
 * business-repository.ts entirely except verifySupplier.
 */
class ProjectService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $project = BusinessValidator::project($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'project' => $project]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_PROJECT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $this->requireCustomerRelationship($project['customer_party_id'], $organisation->id);

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($project, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Project::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'code' => $project['code'], 'name' => $project['name'],
                'customer_party_id' => $project['customer_party_id'], 'manager_user_id' => $actor->id, 'currency' => $project['currency'],
                'start_date' => $project['start_date'], 'end_date' => $project['end_date'], 'status' => 'PLANNED',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($project['budget_cents'] !== null) {
                ProjectBudget::create([
                    'id' => (string) Str::uuid(), 'project_id' => $id, 'category' => 'TOTAL', 'amount_cents' => $project['budget_cents'],
                    'approved_amount_cents' => 0, 'status' => 'PROPOSED', 'approved_by' => null, 'approved_at' => null, 'created_at' => $now,
                ]);
            }
            CommandLedger::record($actor->id, 'CREATE_PROJECT', $idempotencyKey, $requestHash, 'PROJECT', $id, $now);
            CommandLedger::outbox('PROJECT', $id, 'ProjectCreated', $organisation->id, ['project_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PROJECT_CREATED', 'PROJECT', $id, ['organisationId' => $organisation->id, 'code' => $project['code'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /**
     * Acts on the project's one 'TOTAL' budget row -- the only category
     * createProject ever inserts. Maker-checker: the project's own manager
     * (set to whoever called create()) cannot approve their own project's
     * budget, the same self-review rule ExpenseService's approve/reject use.
     *
     * @return array<string, mixed>
     */
    public function approveBudget(string $projectId, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::projectBudgetApproval($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $project = $this->loadProject($projectId, $organisation->id);
        $budget = ProjectBudget::where('project_id', $projectId)->where('category', 'TOTAL')->first();
        if (! $budget) {
            throw new BusinessResourceException('This project has no proposed budget to approve.', 404);
        }
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'project_id' => $projectId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_PROJECT_BUDGET', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentBudget($this->findBudgetOrFail($prior));
        }
        if ($budget->status !== 'PROPOSED') {
            throw new RepositoryConflictException("Only a proposed budget can be approved; this project's budget is currently {$budget->status}.");
        }
        if ($project->manager_user_id && $actor->id === $project->manager_user_id) {
            throw new AuthorizationException("Maker-checker separation prevents the project's own manager from approving its budget.");
        }

        $now = now();
        DB::transaction(function () use ($budget, $organisation, $actor, $projectId, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            ProjectBudget::where('id', $budget->id)->update(['status' => 'APPROVED', 'approved_amount_cents' => $input['approved_amount_cents'], 'approved_by' => $actor->id, 'approved_at' => $now]);
            CommandLedger::record($actor->id, 'APPROVE_PROJECT_BUDGET', $idempotencyKey, $requestHash, 'PROJECT_BUDGET', $budget->id, $now);
            CommandLedger::outbox('PROJECT_BUDGET', $budget->id, 'ProjectBudgetApproved', $organisation->id, ['project_budget_id' => $budget->id, 'project_id' => $projectId, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PROJECT_BUDGET_APPROVED', 'PROJECT_BUDGET', $budget->id, ['organisationId' => $organisation->id, 'projectId' => $projectId, 'approvedAmountCents' => $input['approved_amount_cents'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentBudget($this->findBudgetOrFail($budget->id));
    }

    /**
     * EXPENSE cites an already-approved expense tagged to this project
     * (amount/currency/date derived from that expense, never re-entered);
     * MANUAL is for expenditure this system has no other record of.
     *
     * @return array<string, mixed>
     */
    public function postCost(string $projectId, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::projectCost($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $this->loadProject($projectId, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'project_id' => $projectId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'POST_PROJECT_COST', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentCost($this->findCostOrFail($prior, $projectId));
        }

        if ($input['cost_type'] === 'EXPENSE') {
            $expense = Expense::where('id', $input['source_id'])->where('organisation_id', $organisation->id)->first();
            if (! $expense) {
                throw new BusinessResourceException('The cited expense was not found in the authorised organisation.', 404);
            }
            if ($expense->status !== 'APPROVED') {
                throw new RepositoryConflictException('Only an approved expense can be posted as a project cost.');
            }
            if ($expense->project_id !== $projectId) {
                throw new RepositoryConflictException('This expense is not tagged to the project it is being posted against.');
            }
            $alreadyPosted = ProjectCost::where('project_id', $projectId)->where('cost_type', 'EXPENSE')->where('source_id', $input['source_id'])->first();
            if ($alreadyPosted) {
                throw new RepositoryConflictException("This expense was already posted as project cost {$alreadyPosted->id}.");
            }
            $amountCents = (int) $expense->total_cents;
            $currency = $expense->currency;
            $occurredAt = $expense->expense_date->toDateString();
            $description = $expense->description;
        } else {
            $amountCents = $input['amount_cents'];
            $currency = $input['currency'];
            $occurredAt = $input['occurred_at'];
            $description = $input['description'];
        }

        $id = (string) Str::uuid();
        $now = now();
        try {
            DB::transaction(function () use ($input, $organisation, $actor, $projectId, $id, $amountCents, $currency, $occurredAt, $description, $now, $idempotencyKey, $requestHash, $correlationId) {
                ProjectCost::create([
                    'id' => $id, 'project_id' => $projectId, 'cost_type' => $input['cost_type'], 'source_id' => $input['source_id'],
                    'amount_cents' => $amountCents, 'currency' => $currency, 'description' => $description, 'occurred_at' => $occurredAt,
                    'created_by' => $actor->id, 'created_at' => $now,
                ]);
                CommandLedger::record($actor->id, 'POST_PROJECT_COST', $idempotencyKey, $requestHash, 'PROJECT_COST', $id, $now);
                CommandLedger::outbox('PROJECT_COST', $id, 'ProjectCostPosted', $organisation->id, ['project_cost_id' => $id, 'project_id' => $projectId, 'cost_type' => $input['cost_type'], 'correlation_id' => $correlationId], $now);
                AuditService::append($actor, 'PROJECT_COST_POSTED', 'PROJECT_COST', $id, ['organisationId' => $organisation->id, 'projectId' => $projectId, 'costType' => $input['cost_type'], 'amountCents' => $amountCents, 'correlationId' => $correlationId], $now);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            throw new RepositoryConflictException('This source was already posted as a project cost -- supersession is not supported, only a single posting per source.');
        }

        return $this->presentCost($this->findCostOrFail($id, $projectId));
    }

    /**
     * Revenue reuses the accounting infrastructure rather than inventing a
     * second revenue concept -- REVENUE-type journal_lines already carry
     * project_id, so this sums exactly the postings an accountant tagged
     * to this project.
     *
     * @return array<string, mixed>
     */
    public function profitability(string $projectId, User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $project = $this->loadProject($projectId, $organisation->id);

        $budget = ProjectBudget::where('project_id', $projectId)->where('category', 'TOTAL')->first();
        $costCents = (int) (ProjectCost::where('project_id', $projectId)->sum('amount_cents') ?? 0);
        $revenueCents = (int) (DB::table('journal_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.project_id', $projectId)->where('chart_of_accounts.account_type', 'REVENUE')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_cents),0) - COALESCE(SUM(journal_lines.debit_cents),0) as net_cents')
            ->value('net_cents') ?? 0);

        return [
            'project_id' => $projectId, 'currency' => $project->currency,
            'budget' => $budget ? $this->presentBudget($budget) : null,
            'revenue_cents' => $revenueCents, 'cost_cents' => $costCents, 'profit_cents' => $revenueCents - $costCents,
        ];
    }

    // -- internals --

    private function requireCustomerRelationship(?string $partyId, string $organisationId): void
    {
        if (! $partyId) {
            return;
        }
        $exists = DB::table('business_parties')->where('business_parties.id', $partyId)->where('business_parties.organisation_id', $organisationId)->where('business_parties.status', 'ACTIVE')
            ->whereExists(function ($sub) use ($partyId) {
                $sub->select(DB::raw(1))->from('party_relationships')->whereColumn('party_relationships.party_id', 'business_parties.id')
                    ->where('party_relationships.relationship', 'CUSTOMER')->where('party_relationships.status', 'ACTIVE');
            })->exists();
        if (! $exists) {
            throw new BusinessResourceException('Customer party is not an active customer in the authorised organisation.', 422);
        }
    }

    private function loadProject(string $projectId, string $organisationId): Project
    {
        $project = Project::where('id', $projectId)->where('organisation_id', $organisationId)->first();
        if (! $project) {
            throw new BusinessResourceException('Project was not found in the authorised organisation.', 404);
        }

        return $project;
    }

    private function findOrFail(string $id, string $organisationId): array
    {
        $project = Project::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $project) {
            throw new BusinessResourceException('Project was not found in the authorised organisation.', 404);
        }

        return $this->present($project);
    }

    private function present(Project $project): array
    {
        $budget = ProjectBudget::where('project_id', $project->id)->where('category', 'TOTAL')->first();

        return [
            'id' => $project->id, 'organisation_id' => $project->organisation_id, 'code' => $project->code, 'name' => $project->name,
            'customer_party_id' => $project->customer_party_id, 'manager_user_id' => $project->manager_user_id, 'currency' => $project->currency,
            'start_date' => $project->start_date->toDateString(), 'end_date' => optional($project->end_date)->toDateString(), 'status' => $project->status,
            'created_at' => optional($project->created_at)->toISOString(), 'updated_at' => optional($project->updated_at)->toISOString(),
            'budget' => $budget ? $this->presentBudget($budget) : null,
        ];
    }

    private function findBudgetOrFail(string $id): ProjectBudget
    {
        $budget = ProjectBudget::find($id);
        if (! $budget) {
            throw new BusinessResourceException('Project budget was not found.', 404);
        }

        return $budget;
    }

    private function presentBudget(ProjectBudget $budget): array
    {
        return [
            'id' => $budget->id, 'project_id' => $budget->project_id, 'category' => $budget->category, 'amount_cents' => (int) $budget->amount_cents,
            'approved_amount_cents' => (int) $budget->approved_amount_cents, 'status' => $budget->status, 'approved_by' => $budget->approved_by,
            'approved_at' => optional($budget->approved_at)->toISOString(), 'created_at' => optional($budget->created_at)->toISOString(),
        ];
    }

    private function findCostOrFail(string $id, string $projectId): ProjectCost
    {
        $cost = ProjectCost::where('id', $id)->where('project_id', $projectId)->first();
        if (! $cost) {
            throw new BusinessResourceException('Project cost was not found.', 404);
        }

        return $cost;
    }

    private function presentCost(ProjectCost $cost): array
    {
        return [
            'id' => $cost->id, 'project_id' => $cost->project_id, 'cost_type' => $cost->cost_type, 'source_id' => $cost->source_id,
            'amount_cents' => (int) $cost->amount_cents, 'currency' => $cost->currency, 'description' => $cost->description,
            'occurred_at' => $cost->occurred_at->toDateString(), 'created_by' => $cost->created_by, 'created_at' => optional($cost->created_at)->toISOString(),
        ];
    }
}

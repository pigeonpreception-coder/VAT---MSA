<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's createExpenseCategory/
 * createExpense/submitExpense/approveExpense/rejectExpense/getExpenseReport
 * -- Module 5 Phase E, the third Phase 10 slice. DRAFT -> SUBMITTED ->
 * APPROVED/REJECTED, with maker-checker separation: the actor who created
 * an expense can never approve or reject it themselves.
 */
class ExpenseService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function createCategory(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $category = BusinessValidator::expenseCategory($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'category' => $category]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_EXPENSE_CATEGORY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentCategory($this->findCategoryOrFail($prior, $organisation->id));
        }
        $existing = ExpenseCategory::where('organisation_id', $organisation->id)->where('code', $category['code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("Category code {$category['code']} is already in use ({$existing->name}, {$existing->id}).");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($category, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            ExpenseCategory::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'code' => $category['code'], 'name' => $category['name'],
                'default_tax_category' => $category['default_tax_category'], 'requires_receipt' => $category['requires_receipt'],
                'status' => 'ACTIVE', 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_EXPENSE_CATEGORY', $idempotencyKey, $requestHash, 'EXPENSE_CATEGORY', $id, $now);
            CommandLedger::outbox('EXPENSE_CATEGORY', $id, 'ExpenseCategoryCreated', $organisation->id, ['category_id' => $id, 'organisation_id' => $organisation->id, 'code' => $category['code'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'EXPENSE_CATEGORY_CREATED', 'EXPENSE_CATEGORY', $id, ['organisationId' => $organisation->id, 'code' => $category['code'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentCategory($this->findCategoryOrFail($id, $organisation->id));
    }

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $expense = BusinessValidator::expense($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'expense' => $expense]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_EXPENSE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $this->requireOwnedCategory($expense['category_id'], $organisation->id);
        $this->requireSupplierRelationship($expense['supplier_party_id'], $organisation->id);
        $this->requireOwnedBranch($expense['branch_id'], $organisation->id);
        // project_id: no ownership check yet -- projects (Phase 10's own later
        // sub-slice) has no table to check against, matching the expenses
        // migration's own documented gap.

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($expense, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            Expense::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'branch_id' => $expense['branch_id'], 'category_id' => $expense['category_id'],
                'supplier_party_id' => $expense['supplier_party_id'], 'project_id' => $expense['project_id'], 'expense_number' => $expense['expense_number'],
                'expense_date' => $expense['expense_date'], 'description' => $expense['description'], 'currency' => $expense['currency'],
                'net_cents' => $expense['net_cents'], 'tax_cents' => $expense['tax_cents'], 'total_cents' => $expense['total_cents'],
                'status' => 'DRAFT', 'receipt_document_id' => null, 'created_by' => $actor->id, 'approved_by' => null,
                'created_at' => $now, 'approved_at' => null,
            ]);
            CommandLedger::record($actor->id, 'CREATE_EXPENSE', $idempotencyKey, $requestHash, 'EXPENSE', $id, $now);
            CommandLedger::outbox('EXPENSE', $id, 'ExpenseRecorded', $organisation->id, ['expense_id' => $id, 'organisation_id' => $organisation->id, 'total_cents' => $expense['total_cents'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'EXPENSE_RECORDED', 'EXPENSE', $id, ['organisationId' => $organisation->id, 'expenseNumber' => $expense['expense_number'], 'totalCents' => $expense['total_cents'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** DRAFT -> SUBMITTED, the maker-checker gate's starting line. @return array<string, mixed> */
    public function submit(string $expenseId, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $expense = $this->loadForTransition($expenseId, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'expense_id' => $expenseId, 'action' => 'SUBMIT']);
        $prior = CommandLedger::prior($actor->id, 'SUBMIT_EXPENSE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($expense->status !== 'DRAFT') {
            throw new RepositoryConflictException("Only a draft expense can be submitted; {$expenseId} is currently {$expense->status}.");
        }
        $now = now();
        DB::transaction(function () use ($expenseId, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            Expense::where('id', $expenseId)->update(['status' => 'SUBMITTED']);
            CommandLedger::record($actor->id, 'SUBMIT_EXPENSE', $idempotencyKey, $requestHash, 'EXPENSE', $expenseId, $now);
            CommandLedger::outbox('EXPENSE', $expenseId, 'ExpenseSubmitted', $organisation->id, ['expense_id' => $expenseId, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'EXPENSE_SUBMITTED', 'EXPENSE', $expenseId, ['organisationId' => $organisation->id, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($expenseId, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function approve(string $expenseId, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $expense = $this->loadForTransition($expenseId, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'expense_id' => $expenseId, 'action' => 'APPROVE']);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_EXPENSE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($expense->status !== 'SUBMITTED') {
            throw new RepositoryConflictException("Only a submitted expense can be approved; {$expenseId} is currently {$expense->status}.");
        }
        $this->assertNotSelfReview($actor, $expense->created_by, 'approving');
        $now = now();
        DB::transaction(function () use ($expense, $expenseId, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            Expense::where('id', $expenseId)->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => $now]);
            CommandLedger::record($actor->id, 'APPROVE_EXPENSE', $idempotencyKey, $requestHash, 'EXPENSE', $expenseId, $now);
            CommandLedger::outbox('EXPENSE', $expenseId, 'ExpenseApproved', $organisation->id, ['expense_id' => $expenseId, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'EXPENSE_APPROVED', 'EXPENSE', $expenseId, ['organisationId' => $organisation->id, 'totalCents' => (int) $expense->total_cents, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($expenseId, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function reject(string $expenseId, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::expenseRejection($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $expense = $this->loadForTransition($expenseId, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'expense_id' => $expenseId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REJECT_EXPENSE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($expense->status !== 'SUBMITTED') {
            throw new RepositoryConflictException("Only a submitted expense can be rejected; {$expenseId} is currently {$expense->status}.");
        }
        $this->assertNotSelfReview($actor, $expense->created_by, 'rejecting');
        $now = now();
        DB::transaction(function () use ($expenseId, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            Expense::where('id', $expenseId)->update(['status' => 'REJECTED', 'approved_by' => $actor->id, 'approved_at' => $now, 'rejection_reason' => $input['reason']]);
            CommandLedger::record($actor->id, 'REJECT_EXPENSE', $idempotencyKey, $requestHash, 'EXPENSE', $expenseId, $now);
            CommandLedger::outbox('EXPENSE', $expenseId, 'ExpenseRejected', $organisation->id, ['expense_id' => $expenseId, 'organisation_id' => $organisation->id, 'reason' => $input['reason'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'EXPENSE_REJECTED', 'EXPENSE', $expenseId, ['organisationId' => $organisation->id, 'reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($expenseId, $organisation->id);
    }

    /** Totals by status and by category over [from, to], plus the matching line items. @return array<string, mixed> */
    public function report(User $actor, ?string $requestedOrganisationId, string $from, string $to): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $byStatus = Expense::where('organisation_id', $organisation->id)->whereBetween('expense_date', [$from, $to])
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total_cents),0) as total_cents')->groupBy('status')->get();
        $byCategory = Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expenses.organisation_id', $organisation->id)->whereBetween('expenses.expense_date', [$from, $to])
            ->selectRaw('expense_categories.id as category_id, expense_categories.name as category_name, COUNT(*) as count, COALESCE(SUM(expenses.total_cents),0) as total_cents')
            ->groupBy('expense_categories.id', 'expense_categories.name')->orderByDesc('total_cents')->get();
        $items = Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expenses.organisation_id', $organisation->id)->whereBetween('expenses.expense_date', [$from, $to])
            ->orderByDesc('expenses.expense_date')->limit(500)
            ->select('expenses.*', 'expense_categories.name as category_name')->get();

        $totalCents = (int) $byStatus->sum('total_cents');

        return [
            'organisation_id' => $organisation->id, 'from' => $from, 'to' => $to, 'total_cents' => $totalCents,
            'by_status' => $byStatus->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count, 'total_cents' => (int) $r->total_cents])->values()->all(),
            'by_category' => $byCategory->map(fn ($r) => ['category_id' => $r->category_id, 'category_name' => $r->category_name, 'count' => (int) $r->count, 'total_cents' => (int) $r->total_cents])->values()->all(),
            'items' => $items->map(fn (Expense $e) => [
                'id' => $e->id, 'expense_number' => $e->expense_number, 'expense_date' => $e->expense_date->toDateString(),
                'description' => $e->description, 'category_name' => $e->category_name, 'total_cents' => (int) $e->total_cents, 'status' => $e->status,
            ])->values()->all(),
        ];
    }

    // -- internals --

    /** Maker-checker separation: the actor who created an expense can never approve or reject it themselves, the same "cannot review your own request" rule Phase 9's refund workflow would establish once ported. */
    private function assertNotSelfReview(User $actor, string $createdBy, string $action): void
    {
        if ($actor->id === $createdBy) {
            throw new AuthorizationException("Maker-checker separation prevents {$action} an expense you created yourself.");
        }
    }

    private function loadForTransition(string $expenseId, string $organisationId): Expense
    {
        $expense = Expense::where('id', $expenseId)->where('organisation_id', $organisationId)->first();
        if (! $expense) {
            throw new BusinessResourceException('Expense was not found in the authorised organisation.', 404);
        }

        return $expense;
    }

    private function requireOwnedCategory(string $categoryId, string $organisationId): void
    {
        $exists = ExpenseCategory::where('id', $categoryId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Expense category does not exist in the authorised organisation.', 422);
        }
    }

    private function requireSupplierRelationship(?string $partyId, string $organisationId): void
    {
        if (! $partyId) {
            return;
        }
        $row = BusinessParty::where('business_parties.id', $partyId)->where('business_parties.organisation_id', $organisationId)->where('business_parties.status', 'ACTIVE')
            ->whereHas('relationships', fn ($q) => $q->where('relationship', 'SUPPLIER')->where('status', 'ACTIVE'))
            ->first();
        if (! $row) {
            throw new BusinessResourceException('Supplier party is not an active supplier in the authorised organisation.', 422);
        }
    }

    private function requireOwnedBranch(?string $branchId, string $organisationId): void
    {
        if (! $branchId) {
            return;
        }
        $exists = DB::table('branches')->where('id', $branchId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Branch does not exist in the authorised organisation.', 422);
        }
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id, string $organisationId): array
    {
        $expense = Expense::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $expense) {
            throw new BusinessResourceException('Expense was not found in the authorised organisation.', 404);
        }

        return $this->present($expense);
    }

    /**
     * Ported from lib/data/business-repository.ts's own expense read shape --
     * category_name/supplier_name/receipt_document_id were missing from this
     * port's present() until now (the source's own dashboard query joins
     * expense_categories and business_parties for exactly these fields);
     * closing that gap here benefits every caller (JSON API and the new
     * Blade operations view alike), not a second query path.
     *
     * @return array<string, mixed>
     */
    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id, 'organisation_id' => $expense->organisation_id, 'branch_id' => $expense->branch_id,
            'category_id' => $expense->category_id, 'category_name' => optional($expense->category)->name,
            'supplier_party_id' => $expense->supplier_party_id, 'supplier_name' => optional($expense->supplier)->display_name,
            'project_id' => $expense->project_id,
            'expense_number' => $expense->expense_number, 'expense_date' => $expense->expense_date->toDateString(),
            'description' => $expense->description, 'currency' => $expense->currency, 'net_cents' => (int) $expense->net_cents,
            'tax_cents' => (int) $expense->tax_cents, 'total_cents' => (int) $expense->total_cents, 'status' => $expense->status,
            'receipt_document_id' => $expense->receipt_document_id,
            'created_by' => $expense->created_by, 'approved_by' => $expense->approved_by,
            'created_at' => optional($expense->created_at)->toISOString(), 'approved_at' => optional($expense->approved_at)->toISOString(),
            'rejection_reason' => $expense->rejection_reason,
        ];
    }

    private function findCategoryOrFail(string $id, string $organisationId): ExpenseCategory
    {
        $category = ExpenseCategory::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $category) {
            throw new BusinessResourceException('Expense category was not found in the authorised organisation.', 404);
        }

        return $category;
    }

    private function presentCategory(ExpenseCategory $category): array
    {
        return [
            'id' => $category->id, 'organisation_id' => $category->organisation_id, 'code' => $category->code, 'name' => $category->name,
            'default_tax_category' => $category->default_tax_category, 'requires_receipt' => (bool) $category->requires_receipt,
            'status' => $category->status, 'created_at' => optional($category->created_at)->toISOString(),
        ];
    }
}

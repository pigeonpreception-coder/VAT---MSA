<?php

namespace App\Services\Business;

use App\Exceptions\BusinessResourceException;
use App\Models\BusinessParty;
use App\Models\Expense;
use App\Models\User;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Collection;

/**
 * Backs the "Supplier Ledger" sidebar item (routes/web.php's own
 * `$plannedRoute('/accounting/supplier-ledger', ...)` until now), a
 * $plannedRoute placeholder whose own copy promised "per-supplier posted
 * balances derived from the general ledger". Read in full first: nothing
 * in this codebase ever posts a JournalEntry when an expense is approved
 * (ExpenseService::approve has no ledger side effect at all), and
 * journal_lines carries no supplier attribution column regardless -- the
 * only real per-supplier posted-amount data in the platform today is
 * Expense.supplier_party_id (a BusinessParty with an active SUPPLIER
 * PartyRelationship, the same register Business Parties/Operations
 * already use). Confirmed with the user directly (two designs offered:
 * derive from Expense as-is, or first wire real GL postings into the
 * approval flow) -- derive from Expense as-is was chosen, so this reads
 * existing, already-shipped data rather than changing ExpenseService's
 * own already-tested approval behaviour.
 *
 * An expense's own maker-checker lifecycle (DRAFT -> SUBMITTED ->
 * APPROVED/REJECTED) has no separate "posted"/"paid" status, so APPROVED
 * is treated as "posted" here (the same maker-checker-approved meaning
 * "posted" carries everywhere else this platform uses it, e.g. a
 * JournalEntry's own POSTED status). SUBMITTED (awaiting approval) is
 * shown as a separate "pending" figure, never folded into the posted
 * balance -- an unapproved expense is not yet a recognised liability to
 * the supplier.
 */
class SupplierLedgerService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** Every active SUPPLIER-relationship party in scope, for the ledger's own supplier picker. */
    public function supplierOptions(User $actor, ?string $requestedOrganisationId): Collection
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        return BusinessParty::where('organisation_id', $organisation->id)
            ->whereHas('relationships', fn ($q) => $q->where('relationship', 'SUPPLIER')->where('status', 'ACTIVE'))
            ->orderBy('display_name')->get();
    }

    /** @return array<string, mixed> */
    public function summary(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $rows = Expense::query()
            ->join('business_parties', 'business_parties.id', '=', 'expenses.supplier_party_id')
            ->where('expenses.organisation_id', $organisation->id)
            ->whereIn('expenses.status', ['APPROVED', 'SUBMITTED'])
            ->groupBy('business_parties.id', 'business_parties.display_name', 'business_parties.vat_number', 'business_parties.status')
            ->orderByDesc('posted_total_cents')
            ->selectRaw(
                'business_parties.id as supplier_party_id, business_parties.display_name, business_parties.vat_number, business_parties.status as supplier_status, '.
                "COUNT(CASE WHEN expenses.status = 'APPROVED' THEN 1 END) as posted_count, ".
                "COALESCE(SUM(CASE WHEN expenses.status = 'APPROVED' THEN expenses.total_cents ELSE 0 END), 0) as posted_total_cents, ".
                "COUNT(CASE WHEN expenses.status = 'SUBMITTED' THEN 1 END) as pending_count, ".
                "COALESCE(SUM(CASE WHEN expenses.status = 'SUBMITTED' THEN expenses.total_cents ELSE 0 END), 0) as pending_total_cents, ".
                'MAX(expenses.expense_date) as last_expense_date',
            )->get();

        $suppliers = $rows->map(fn ($row) => [
            'supplier_party_id' => $row->supplier_party_id, 'display_name' => $row->display_name, 'vat_number' => $row->vat_number,
            'supplier_status' => $row->supplier_status, 'posted_count' => (int) $row->posted_count, 'posted_total_cents' => (int) $row->posted_total_cents,
            'pending_count' => (int) $row->pending_count, 'pending_total_cents' => (int) $row->pending_total_cents,
            'last_expense_date' => $row->last_expense_date,
        ])->values()->all();

        $unassigned = Expense::where('organisation_id', $organisation->id)->whereNull('supplier_party_id')
            ->whereIn('status', ['APPROVED', 'SUBMITTED'])
            ->selectRaw(
                "COUNT(CASE WHEN status = 'APPROVED' THEN 1 END) as posted_count, ".
                "COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN total_cents ELSE 0 END), 0) as posted_total_cents, ".
                "COUNT(CASE WHEN status = 'SUBMITTED' THEN 1 END) as pending_count, ".
                "COALESCE(SUM(CASE WHEN status = 'SUBMITTED' THEN total_cents ELSE 0 END), 0) as pending_total_cents",
            )->first();

        return [
            'organisation_id' => $organisation->id,
            'suppliers' => $suppliers,
            'unassigned' => [
                'posted_count' => (int) $unassigned->posted_count, 'posted_total_cents' => (int) $unassigned->posted_total_cents,
                'pending_count' => (int) $unassigned->pending_count, 'pending_total_cents' => (int) $unassigned->pending_total_cents,
            ],
            'total_posted_cents' => array_sum(array_column($suppliers, 'posted_total_cents')) + (int) $unassigned->posted_total_cents,
            'total_pending_cents' => array_sum(array_column($suppliers, 'pending_total_cents')) + (int) $unassigned->pending_total_cents,
        ];
    }

    /**
     * One supplier's own statement: every expense posted against them
     * (APPROVED, SUBMITTED or REJECTED, oldest first, for full
     * transparency), with a running posted balance that only APPROVED
     * lines move -- a SUBMITTED or REJECTED line is shown but never
     * changes the running total, the same "not yet a recognised
     * liability" reasoning as summary()'s own pending figure.
     *
     * @return array<string, mixed>
     */
    public function statement(User $actor, ?string $requestedOrganisationId, string $supplierPartyId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        $supplier = BusinessParty::where('id', $supplierPartyId)->where('organisation_id', $organisation->id)->first();
        if (! $supplier) {
            throw new BusinessResourceException('Supplier was not found in the authorised organisation.', 404);
        }

        $expenses = Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expenses.organisation_id', $organisation->id)->where('expenses.supplier_party_id', $supplierPartyId)
            ->whereIn('expenses.status', ['APPROVED', 'SUBMITTED', 'REJECTED'])
            ->orderBy('expenses.expense_date')->orderBy('expenses.created_at')
            ->select('expenses.*', 'expense_categories.name as category_name')
            ->get();

        $runningCents = 0;
        $lines = $expenses->map(function (Expense $expense) use (&$runningCents) {
            if ($expense->status === 'APPROVED') {
                $runningCents += (int) $expense->total_cents;
            }

            return [
                'id' => $expense->id, 'expense_number' => $expense->expense_number, 'expense_date' => $expense->expense_date->toDateString(),
                'category_name' => $expense->category_name, 'description' => $expense->description,
                'total_cents' => (int) $expense->total_cents, 'status' => $expense->status,
                'running_balance_cents' => $expense->status === 'APPROVED' ? $runningCents : null,
            ];
        })->values()->all();

        return [
            'organisation_id' => $organisation->id,
            'supplier' => ['id' => $supplier->id, 'display_name' => $supplier->display_name, 'legal_name' => $supplier->legal_name, 'vat_number' => $supplier->vat_number, 'status' => $supplier->status],
            'lines' => $lines, 'posted_balance_cents' => $runningCents,
        ];
    }
}

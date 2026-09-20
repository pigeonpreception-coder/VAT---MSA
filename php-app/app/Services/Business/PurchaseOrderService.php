<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
use App\Models\ExpenseCategory;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * New service, not ported from the TS source -- closes the
 * `accounting.purchase-orders` $plannedRoute placeholder ("Purchase order
 * issuance, approval and conversion to supplier invoices"). See
 * database/migrations/2026_09_19_000000_create_purchase_orders_table.php's
 * own doc comment for why the table is single-amount (not a multi-line
 * catalogue like quotations) and for the full lifecycle rationale.
 *
 * DRAFT -> SUBMITTED -> APPROVED -> ISSUED -> CONVERTED, mirroring
 * ExpenseService's own maker-checker shape for the first three
 * transitions (the creator can never approve or reject their own order --
 * the same self-review guard ExpenseService::approve/reject and
 * ProjectService::approveBudget already establish), then two PO-specific
 * additions: issue() (a plain status flip -- the "issuance" the
 * placeholder's own copy promised) and convertToExpense() (the
 * "conversion" the placeholder promised -- to a real Expense, reusing
 * ExpenseService::create() directly rather than a second write path,
 * since this platform has no inbound-supplier-invoice concept distinct
 * from Expense). cancel() is the escape hatch a real purchasing flow
 * needs that expenses/quotations don't (an order can be called off any
 * time before conversion; an already-recorded expense or an
 * already-certified invoice cannot).
 */
class PurchaseOrderService
{
    public function __construct(private readonly OrganisationResolver $organisations, private readonly ExpenseService $expenses) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $order = BusinessValidator::purchaseOrder($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'order' => $order]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $this->requireSupplierRelationship($order['supplier_party_id'], $organisation->id);
        $this->requireOwnedCategory($order['category_id'], $organisation->id);
        $this->requireOwnedBranch($order['branch_id'], $organisation->id);
        $duplicateNumber = PurchaseOrder::where('organisation_id', $organisation->id)->where('po_number', $order['po_number'])->first();
        if ($duplicateNumber) {
            throw new RepositoryConflictException("Purchase order number {$order['po_number']} already exists as {$duplicateNumber->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($order, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            PurchaseOrder::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'branch_id' => $order['branch_id'],
                'supplier_party_id' => $order['supplier_party_id'], 'category_id' => $order['category_id'], 'po_number' => $order['po_number'],
                'currency' => $order['currency'], 'issue_date' => $order['issue_date'], 'valid_until' => $order['valid_until'],
                'status' => 'DRAFT', 'description' => $order['description'], 'net_cents' => $order['net_cents'],
                'tax_cents' => $order['tax_cents'], 'total_cents' => $order['total_cents'], 'notes' => $order['notes'],
                'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderCreated', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_CREATED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'poNumber' => $order['po_number'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function submit(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'action' => 'SUBMIT']);
        $prior = CommandLedger::prior($actor->id, 'SUBMIT_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($order->status !== 'DRAFT') {
            throw new RepositoryConflictException("Only a draft purchase order can be submitted; {$id} is currently {$order->status}.");
        }
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $updated = PurchaseOrder::where('id', $id)->where('status', 'DRAFT')->update(['status' => 'SUBMITTED', 'updated_at' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Purchase order {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'SUBMIT_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderSubmitted', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_SUBMITTED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function approve(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'action' => 'APPROVE']);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($order->status !== 'SUBMITTED') {
            throw new RepositoryConflictException("Only a submitted purchase order can be approved; {$id} is currently {$order->status}.");
        }
        $this->assertNotSelfReview($actor, $order->created_by, 'approving');
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $updated = PurchaseOrder::where('id', $id)->where('status', 'SUBMITTED')
                ->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => $now, 'updated_at' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Purchase order {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'APPROVE_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderApproved', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_APPROVED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function reject(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::purchaseOrderRejection($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REJECT_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($order->status !== 'SUBMITTED') {
            throw new RepositoryConflictException("Only a submitted purchase order can be rejected; {$id} is currently {$order->status}.");
        }
        $this->assertNotSelfReview($actor, $order->created_by, 'rejecting');
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            $updated = PurchaseOrder::where('id', $id)->where('status', 'SUBMITTED')
                ->update(['status' => 'REJECTED', 'approved_by' => $actor->id, 'approved_at' => $now, 'rejection_reason' => $input['reason'], 'updated_at' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Purchase order {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'REJECT_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderRejected', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'reason' => $input['reason'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_REJECTED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function issue(string $id, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'action' => 'ISSUE']);
        $prior = CommandLedger::prior($actor->id, 'ISSUE_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if ($order->status !== 'APPROVED') {
            throw new RepositoryConflictException("Only an approved purchase order can be issued; {$id} is currently {$order->status}.");
        }
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $updated = PurchaseOrder::where('id', $id)->where('status', 'APPROVED')->update(['status' => 'ISSUED', 'issued_at' => $now, 'updated_at' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Purchase order {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'ISSUE_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderIssued', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_ISSUED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /**
     * Reuses ExpenseService::create() directly -- never a second write
     * path -- carrying the order's own category/supplier/amounts across
     * 1:1. The resulting Expense immediately shows up on that supplier's
     * own Supplier Ledger statement once approved there in turn, the same
     * way QuotationService::convertToInvoice's own certified invoice feeds
     * the Customer Ledger.
     *
     * @return array<string, mixed>
     */
    public function convertToExpense(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $expenseNumber = mb_strtoupper(trim((string) ($payload['expense_number'] ?? '')));
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'expense_number' => $expenseNumber]);
        $prior = CommandLedger::prior($actor->id, 'CONVERT_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($id, $organisation->id);
        }
        if ($order->status !== 'ISSUED') {
            throw new RepositoryConflictException("Only an issued purchase order can be converted; {$id} is currently {$order->status}.");
        }
        if ($expenseNumber === '') {
            throw new RepositoryConflictException('An expense number is required to convert this purchase order.');
        }

        $expensePayload = [
            'schema_version' => '1.0.0', 'category_id' => $order->category_id, 'supplier_party_id' => $order->supplier_party_id,
            'project_id' => null, 'branch_id' => $order->branch_id, 'expense_number' => $expenseNumber,
            'expense_date' => now()->toDateString(), 'description' => "Purchase order {$order->po_number}: {$order->description}",
            'currency' => $order->currency, 'net_cents' => (int) $order->net_cents, 'tax_cents' => (int) $order->tax_cents,
            'total_cents' => (int) $order->total_cents,
        ];
        // Expense certification is independently idempotent. If this process stops
        // after that commit, the same key reloads the created expense and safely
        // finishes purchase-order linkage below.
        $expense = $this->expenses->create($expensePayload, $actor, $idempotencyKey, $correlationId, $requestedOrganisationId);
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $expense, $now, $idempotencyKey, $requestHash, $correlationId) {
            PurchaseOrder::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ISSUED')
                ->update(['status' => 'CONVERTED', 'converted_expense_id' => $expense['id'], 'updated_at' => $now]);
            CommandLedger::record($actor->id, 'CONVERT_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'EXPENSE', $expense['id'], $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderConverted', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'expense_id' => $expense['id'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_CONVERTED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'expenseId' => $expense['id'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function cancel(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = BusinessValidator::purchaseOrderCancellation($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $order = $this->loadForTransition($id, $organisation->id);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'purchase_order_id' => $id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CANCEL_PURCHASE_ORDER', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        if (! in_array($order->status, ['DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED'], true)) {
            throw new RepositoryConflictException("A purchase order can only be cancelled before conversion or rejection; {$id} is currently {$order->status}.");
        }
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input, $order) {
            $updated = PurchaseOrder::where('id', $id)->where('status', $order->status)
                ->update(['status' => 'CANCELLED', 'cancellation_reason' => $input['reason'], 'updated_at' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Purchase order {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'CANCEL_PURCHASE_ORDER', $idempotencyKey, $requestHash, 'PURCHASE_ORDER', $id, $now);
            CommandLedger::outbox('PURCHASE_ORDER', $id, 'PurchaseOrderCancelled', $organisation->id, ['purchase_order_id' => $id, 'organisation_id' => $organisation->id, 'reason' => $input['reason'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'PURCHASE_ORDER_CANCELLED', 'PURCHASE_ORDER', $id, ['organisationId' => $organisation->id, 'reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    // -- internals --

    /** Maker-checker separation: the actor who created a purchase order can never approve or reject it themselves. */
    private function assertNotSelfReview(User $actor, string $createdBy, string $action): void
    {
        if ($actor->id === $createdBy) {
            throw new AuthorizationException("Maker-checker separation prevents {$action} a purchase order you created yourself.");
        }
    }

    private function loadForTransition(string $id, string $organisationId): PurchaseOrder
    {
        $order = PurchaseOrder::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $order) {
            throw new BusinessResourceException('Purchase order was not found in the authorised organisation.', 404);
        }

        return $order;
    }

    private function requireSupplierRelationship(string $partyId, string $organisationId): void
    {
        $row = BusinessParty::where('business_parties.id', $partyId)->where('business_parties.organisation_id', $organisationId)->where('business_parties.status', 'ACTIVE')
            ->whereHas('relationships', fn ($q) => $q->where('relationship', 'SUPPLIER')->where('status', 'ACTIVE'))
            ->first();
        if (! $row) {
            throw new BusinessResourceException('Supplier party is not an active supplier in the authorised organisation.', 422);
        }
    }

    private function requireOwnedCategory(string $categoryId, string $organisationId): void
    {
        $exists = ExpenseCategory::where('id', $categoryId)->where('organisation_id', $organisationId)->exists();
        if (! $exists) {
            throw new BusinessResourceException('Expense category does not exist in the authorised organisation.', 422);
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
        $order = PurchaseOrder::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $order) {
            throw new BusinessResourceException('Purchase order was not found in the authorised organisation.', 404);
        }

        return $this->present($order);
    }

    /** @return array<string, mixed> */
    private function present(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id, 'organisation_id' => $order->organisation_id, 'branch_id' => $order->branch_id,
            'supplier_party_id' => $order->supplier_party_id, 'supplier_name' => optional($order->supplier)->display_name,
            'category_id' => $order->category_id, 'category_name' => optional($order->category)->name,
            'po_number' => $order->po_number, 'currency' => $order->currency, 'issue_date' => $order->issue_date->toDateString(),
            'valid_until' => $order->valid_until->toDateString(), 'status' => $order->status, 'description' => $order->description,
            'net_cents' => (int) $order->net_cents, 'tax_cents' => (int) $order->tax_cents, 'total_cents' => (int) $order->total_cents,
            'notes' => $order->notes, 'created_by' => $order->created_by, 'approved_by' => $order->approved_by,
            'approved_at' => optional($order->approved_at)->toISOString(), 'rejection_reason' => $order->rejection_reason,
            'cancellation_reason' => $order->cancellation_reason, 'issued_at' => optional($order->issued_at)->toISOString(),
            'converted_expense_id' => $order->converted_expense_id,
            'created_at' => optional($order->created_at)->toISOString(), 'updated_at' => optional($order->updated_at)->toISOString(),
        ];
    }
}

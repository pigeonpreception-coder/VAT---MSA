<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table, not ported from the TS source -- closes the
 * `accounting.purchase-orders` $plannedRoute placeholder ("Purchase order
 * issuance, approval and conversion to supplier invoices"), whose own
 * scope note ("No purchase-order domain model exists in the platform
 * today") was confirmed accurate by a full-repo grep before writing this
 * migration, unlike the Budgets placeholder's stale one.
 *
 * Deliberately a single amount per order (net_cents/tax_cents/total_cents),
 * not a multi-line catalogue like quotations/quotation_lines -- mirrors
 * expenses' own single-amount shape (the conversion target below), which
 * keeps convertToExpense() a direct 1:1 field carry-over rather than
 * synthesizing a multi-line payload, and matches how a lightweight PO is
 * actually used in this platform's own "lighter CRUD standard" (see
 * App\Services\Business\AccountingService's own doc comment).
 *
 * Lifecycle: DRAFT -> SUBMITTED -> APPROVED -> ISSUED -> CONVERTED, with
 * REJECTED (from SUBMITTED, maker-checker -- the creator can never approve
 * their own order, the same self-review guard ExpenseService::approve/
 * ProjectService::approveBudget already establish) and CANCELLED (from any
 * pre-CONVERTED status) as the two terminal alternates. Mirrors
 * ExpenseService's own DRAFT->SUBMITTED->APPROVED/REJECTED shape for the
 * first three transitions (the "approval" the placeholder's own copy
 * promised), then adds ISSUED (a plain status flip, the "issuance" the
 * placeholder promised) and CONVERTED (a real Expense created via
 * ExpenseService::create() directly, the "conversion" the placeholder
 * promised -- to a real supplier expense rather than "supplier invoices"
 * literally, since this platform has no inbound-supplier-invoice concept
 * distinct from Expense; see PurchaseOrderService's own doc comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->foreignUuid('supplier_party_id')->constrained('business_parties');
            $table->foreignUuid('category_id')->constrained('expense_categories');
            $table->string('po_number', 40);
            $table->string('currency', 3);
            $table->date('issue_date');
            $table->date('valid_until');
            $table->string('status', 20);
            $table->text('description');
            $table->bigInteger('net_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('issued_at')->nullable();
            // No FK -- the same plain-reference precedent quotations.converted_invoice_id
            // already establishes (expenses is a cross-service write outside this
            // table's own transaction).
            $table->uuid('converted_expense_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'po_number'], 'purchase_orders_org_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};

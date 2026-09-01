<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `expenses` table -- Module 5 Phase E's
 * maker-checker lifecycle: DRAFT -> SUBMITTED -> APPROVED/REJECTED, the
 * approver/rejecter never the same actor who created the expense (see
 * App\Services\Business\ExpenseService::assertNotSelfReview). `project_id`
 * carries no FK -- projects (Phase 10's own later sub-slice) has no table
 * to check against yet, matching journal_lines'/quotation_lines' own
 * documented gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->foreignUuid('category_id')->constrained('expense_categories');
            $table->foreignUuid('supplier_party_id')->nullable()->constrained('business_parties');
            $table->uuid('project_id')->nullable();
            $table->string('expense_number', 40);
            $table->date('expense_date');
            $table->text('description');
            $table->string('currency', 3);
            $table->bigInteger('net_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->string('status', 20);
            $table->uuid('receipt_document_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unique(['organisation_id', 'expense_number'], 'expenses_org_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `expense_decisions` table -- Module 5 Phase
 * E's DecideExpense, a newer, consolidated maker-checker decision that
 * replaces the older two-step SUBMIT->APPROVE/REJECT flow with a single
 * receipt-gated decision straight from DRAFT (see
 * App\Domain\Business\BusinessValidator::evaluateExpenseDecision() for the
 * exact gate). SUBMIT_EXPENSE/APPROVE_EXPENSE/REJECT_EXPENSE remain
 * unchanged for callers still on that flow -- this is the additive one,
 * matching source exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->unique()->constrained('expenses');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('decision', 20);
            $table->text('reason');
            $table->foreignUuid('decided_by')->constrained('users');
            $table->timestamp('decided_at')->useCurrent();

            $table->index(['organisation_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_decisions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from drizzle/0011_melted_weapon_omega.sql's `expense_receipt_links`
 * table -- Module 5 Phase E LinkExpenseReceipt's own append-only link
 * table: one immutable receipt per draft expense. The two unique indexes
 * below are the real enforcement this port relies on (a given expense or
 * document can appear in at most one row) -- source's own SQLite triggers
 * additionally forbid UPDATE/DELETE outright and re-derive `expenses.
 * receipt_document_id` from the INSERT; this port's
 * App\Services\Business\ExpenseService::linkReceipt() only ever INSERTs
 * here and sets `expenses.receipt_document_id` in the same transaction, so
 * there is nothing an UPDATE/DELETE trigger would ever need to catch --
 * the same "enforce it in the one place the app actually writes, not a
 * second parallel mechanism" posture this port already takes for
 * `user_invitations`'s immutability (no claimed row is ever un-claimed)
 * and `api_clients.credential_reference` history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_receipt_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->unique()->constrained('expenses');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('document_id')->unique()->constrained('document_metadata');
            $table->foreignUuid('linked_by')->constrained('users');
            $table->timestamp('linked_at')->useCurrent();

            $table->index(['organisation_id', 'linked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipt_links');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `journal_entries` table. `reverses_journal_entry_id`
 * self-references this same table -- a posted journal is never edited or
 * deleted, only ever matched by a brand-new, equal-and-opposite reversal
 * entry (see App\Services\Business\AccountingService::reverseJournalEntry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('journal_number', 40);
            $table->date('journal_date');
            $table->string('reference')->nullable();
            $table->text('description');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->enum('source_type', ['MANUAL', 'EXPENSE', 'INVOICE', 'IMPORT', 'ADJUSTMENT']);
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('posted_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('reverses_journal_entry_id')->nullable();
            $table->foreign('reverses_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organisation_id', 'journal_number'], 'journal_entries_org_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

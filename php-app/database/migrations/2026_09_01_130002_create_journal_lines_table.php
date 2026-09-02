<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `journal_lines` table. `project_id` carries
 * no FK here -- the source references `projects(id)`, but Phase 10's
 * projects sub-slice has not been migrated yet (see
 * docs/MIGRATION_MATRIX.md), a documented gap matching quotation_lines'
 * own product_id note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries');
            $table->unsignedInteger('line_number');
            $table->foreignUuid('account_id')->constrained('chart_of_accounts');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->uuid('project_id')->nullable();
            $table->text('description');
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->string('tax_code', 40)->nullable();

            $table->unique(['journal_entry_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};

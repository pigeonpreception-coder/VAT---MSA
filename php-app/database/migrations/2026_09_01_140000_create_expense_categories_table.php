<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `expense_categories` table -- Module 5 Phase E CreateExpenseCategory unstuck this from seed-only data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('code', 20);
            $table->string('name');
            $table->enum('default_tax_category', ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUT_OF_SCOPE']);
            $table->boolean('requires_receipt')->default(true);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'code'], 'expense_categories_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};

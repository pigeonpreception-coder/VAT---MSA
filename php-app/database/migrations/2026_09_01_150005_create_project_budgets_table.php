<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `project_budgets` table. `category` is
 * always 'TOTAL' in practice -- CreateProject is the only writer and never
 * inserts any other category (see App\Services\Business\ProjectService's
 * own note on not inventing a multi-category budget surface the source
 * doesn't have either).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->string('category', 20);
            $table->bigInteger('amount_cents');
            $table->bigInteger('approved_amount_cents');
            $table->string('status', 20);
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budgets');
    }
};

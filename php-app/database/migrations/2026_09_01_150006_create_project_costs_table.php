<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `project_costs` table. The
 * UNIQUE(project_id, cost_type, source_id) constraint is what actually
 * prevents the same EXPENSE from ever being posted as a project cost
 * twice -- App\Services\Business\ProjectService's own pre-check exists
 * only for a clean 409 message ahead of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->enum('cost_type', ['EXPENSE', 'MANUAL']);
            $table->string('source_id');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->text('description')->nullable();
            $table->date('occurred_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'cost_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_costs');
    }
};

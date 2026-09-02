<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_conditions` table -- an optional
 * guard on one transition, restricted to the typed field/operator
 * vocabulary `normalizeWorkflowDefinition`/`evaluateWorkflowCondition`
 * both share (`amount_cents`/`branch_id`/`department_id` and
 * `LTE`/`GT`/`EQ`). `comparison_value` is stored as text regardless of
 * the original field's type, matching the source's own string-comparison
 * fallback in `evaluateWorkflowCondition`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_transition_id')->constrained('workflow_transitions');
            $table->string('field', 40);
            $table->string('operator', 10);
            $table->string('comparison_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_conditions');
    }
};

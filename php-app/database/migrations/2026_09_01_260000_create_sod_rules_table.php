<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `sod_rules` table -- Phase 12's workflow-
 * engine slice (Module 8 Phase C). Like `license_plans`/`tax_rule_sets`
 * before it, confirmed by grepping every .ts file under lib/ that no
 * application command ever creates a row here (only `decideWorkflowTask`
 * reads it, checking for a `NO_SELF_APPROVAL` rule before recording a
 * segregation-of-duties violation) -- seed/deploy-time governance data,
 * scoped per organisation via the nullable `organisation_id` (the
 * source's own demo seed uses it that way; a NULL row applying globally
 * is schema-legal but not exercised by anything in the source either).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sod_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->string('code', 60);
            $table->string('name');
            $table->text('action_set');
            $table->string('scope', 60);
            $table->boolean('mandatory')->default(true);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['code', 'organisation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_rules');
    }
};

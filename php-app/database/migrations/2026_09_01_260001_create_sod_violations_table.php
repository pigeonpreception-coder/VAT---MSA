<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `sod_violations` table --
 * `decideWorkflowTask`'s own write target: a self-approval or disabled
 * emergency-override attempt against a real `NO_SELF_APPROVAL` sod_rule
 * is recorded here (and the original error still re-thrown), rather than
 * silently swallowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sod_violations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('sod_rule_id')->constrained('sod_rules');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('resource_type', 40);
            $table->string('resource_id');
            $table->string('status', 20);
            $table->text('evidence');
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_violations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_approvals` table -- one
 * append-only, immutable decision record per `decideWorkflowTask` call
 * (the source's own `UNIQUE` on `workflow_assignment_id` means an
 * assignment can only ever be decided once, matching its own
 * `status='PENDING'` guard on the assignment update).
 * `authority_snapshot` freezes the deciding actor's role and dynamic
 * permissions at decision time, for later audit -- not re-derived from
 * the user's current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances');
            $table->foreignUuid('workflow_assignment_id')->constrained('workflow_assignments');
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('decision', 20);
            $table->text('reason');
            $table->text('authority_snapshot');
            $table->timestamp('decided_at')->useCurrent();

            $table->unique('workflow_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approvals');
    }
};

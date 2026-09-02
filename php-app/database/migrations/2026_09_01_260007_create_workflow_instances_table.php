<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_instances` table --
 * `assignWorkflow`'s own write target, one row per real approval run
 * against a resource (e.g. one specific purchase request). `resource_id`
 * deliberately has no FK -- `resource_type` names which table it points
 * into, and the source's own schema doesn't constrain it either (a
 * polymorphic reference, the same shape already used elsewhere in this
 * migration, e.g. `audit_events.resource_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions');
            $table->string('resource_type', 40);
            $table->string('resource_id');
            $table->foreignUuid('initiated_by')->constrained('users');
            $table->string('status', 20);
            $table->string('current_node_key', 40);
            $table->text('context_snapshot');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};

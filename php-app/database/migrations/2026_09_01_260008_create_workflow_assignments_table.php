<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_assignments` table -- one pending
 * (or decided) task at the current node of a workflow instance, assigned
 * to either a concrete user or a role (never both -- `resolveAssignee`
 * always sets exactly one of the two).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances');
            $table->string('node_key', 40);
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users');
            $table->foreignUuid('assigned_role_id')->nullable()->constrained('organisation_roles');
            $table->string('status', 20);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_assignments');
    }
};

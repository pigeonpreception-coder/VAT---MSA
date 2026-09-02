<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_nodes` table -- one row per
 * START/APPROVAL/END node in a workflow version's graph. `node_key` is
 * the author-supplied node id (e.g. 'start', 'manager-approval'),
 * distinct from this row's own UUID `id`, matching the source's own
 * `node_key`/`id` split exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions');
            $table->string('node_key', 40);
            $table->string('node_type', 20);
            $table->string('label');
            $table->string('assignee_type', 20)->nullable();
            $table->string('assignee_reference')->nullable();
            $table->unsignedInteger('sequence');

            $table->unique(['workflow_version_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};

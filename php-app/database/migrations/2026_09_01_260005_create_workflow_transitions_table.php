<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_transitions` table -- the graph's
 * edges (`from_node_key` -> `to_node_key`), each referencing the same
 * version's `workflow_nodes.node_key` values by convention rather than a
 * real FK on those columns (matching the source's own plain `TEXT`
 * columns -- validated at write time by `normalizeWorkflowDefinition`
 * instead of a database constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions');
            $table->string('from_node_key', 40);
            $table->string('to_node_key', 40);
            $table->unsignedInteger('sequence');

            $table->unique(['workflow_version_id', 'from_node_key', 'to_node_key'], 'workflow_transitions_version_from_to_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};

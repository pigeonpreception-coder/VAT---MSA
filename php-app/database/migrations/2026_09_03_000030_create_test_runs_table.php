<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `test_runs` table -- an `api_clients`
 * sandbox conformance check battery, genuinely distinct from Phase 12
 * slice 5's `testWorkflowVersion` (which walks a workflow definition with
 * no persistence at all -- see App\Services\Workflow\WorkflowService's
 * own doc comment). No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_client_id')->constrained('api_clients');
            $table->text('checks');
            $table->string('outcome', 20);
            $table->foreignUuid('run_by')->constrained('users');
            $table->timestamp('run_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};

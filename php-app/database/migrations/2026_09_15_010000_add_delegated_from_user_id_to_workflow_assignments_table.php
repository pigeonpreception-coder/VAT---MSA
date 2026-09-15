<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red-team punch list #7 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_2026-09-15.md):
 * WorkflowService::resolveAssignee() resolves a delegation redirect once,
 * at assign time, and overwrites `assigned_user_id` with the delegate's
 * own id -- by decide time nothing records that a delegation was ever
 * involved, so `decideWorkflowTask()` has no way to notice the covering
 * `workflow_delegations` row was revoked in between (a gap that can be
 * the task's entire pending lifetime, not a narrow concurrent window).
 * This column lets decideWorkflowTask() re-check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_assignments', function (Blueprint $table) {
            $table->foreignUuid('delegated_from_user_id')->nullable()->after('assigned_user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegated_from_user_id');
        });
    }
};

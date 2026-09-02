<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_delegations` table --
 * `createDelegation`'s own write target, a time-bounded redirect of one
 * user's assigned tasks to another, either for a single workflow
 * (`workflow_id` set) or every workflow (`workflow_id` NULL, `scope`
 * `ALL`). `effective_from`/`effective_to` are given an explicit
 * `DEFAULT CURRENT_TIMESTAMP` (deliberately without `ON UPDATE`) purely
 * as this migration's now-standard defensive measure -- the application
 * always supplies both explicitly (a delegation's whole point is a
 * caller-chosen window, never "now"), but `revokeDelegation`'s own
 * `UPDATE` never touches either column, and both are this table's
 * NOT-NULL timestamps without a source-given default, the same shape
 * that has caused real corruption twice already in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('delegator_user_id')->constrained('users');
            $table->foreignUuid('delegate_user_id')->constrained('users');
            $table->foreignUuid('workflow_id')->nullable()->constrained('workflows');
            $table->string('scope', 20);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->useCurrent();
            $table->foreignUuid('approved_by')->constrained('users');
            $table->text('reason')->default('');
            $table->text('revoked_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_delegations');
    }
};

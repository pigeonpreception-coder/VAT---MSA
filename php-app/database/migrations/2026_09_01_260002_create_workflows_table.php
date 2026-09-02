<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflows` table -- Phase 12's workflow-
 * engine slice (Module 8 Phase C): `createWorkflowDraft`'s own write
 * target, one row per organisation-defined approval workflow (e.g. one
 * PURCHASE_REQUEST workflow), publication toggling `status` between
 * DRAFT and ACTIVE while the real version history lives in
 * `workflow_versions`. `updated_at` is given an explicit
 * `DEFAULT CURRENT_TIMESTAMP` (deliberately without `ON UPDATE`) even
 * though the application always sets it explicitly on every write
 * (create and publish alike) -- purely to keep `created_at`, the earlier
 * NOT-NULL timestamp column in this table, from being the one MariaDB
 * silently auto-tags instead; the same defensive pattern this migration
 * has followed since the first instance of the bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('name');
            $table->string('domain_action', 40);
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};

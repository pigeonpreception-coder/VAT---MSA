<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_approvals` table -- one append-only
 * row per `decideAccessRequest` decision. `decided_at` is given an
 * explicit `DEFAULT CURRENT_TIMESTAMP` (without `ON UPDATE`) proactively,
 * matching this migration's now-established practice for every new
 * NOT-NULL timestamp column, even though this table is genuinely
 * insert-only (no command anywhere issues an `UPDATE` against it) and so
 * carries no real risk today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('access_request_id')->constrained('access_requests');
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->string('reviewer_stage', 20);
            $table->string('decision', 20);
            $table->text('reason');
            $table->timestamp('decided_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_approvals');
    }
};

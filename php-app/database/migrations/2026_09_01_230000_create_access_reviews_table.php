<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_reviews` table -- pulled forward
 * from Phase 12's own still-deferred Access governance slice specifically
 * because `assertEntitledOperation`'s `ADMIN_WRITE` gate (every write
 * command in this organisation-administration/employees slice) hard-
 * requires an open-or-completed quarterly review to exist before it will
 * allow any privileged organisation change -- the same "unblock the real
 * prerequisite, not invent a shortcut around it" approach already used for
 * the VAT-return-generation prerequisite. Only `openQuarterlyAccessReview`
 * is ported alongside it this slice; `certifyQuarterlyAccess` (the review's
 * own completion path, with its bulk role/capability revocation) and the
 * rest of Access governance remain deferred -- see
 * docs/MIGRATION_MATRIX.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('name');
            $table->string('review_type', 20);
            $table->string('status', 20);
            $table->date('period_start');
            $table->timestamp('due_at');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');
    }
};

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
 *
 * `due_at` amended (Phase 12 slice 4, before `certifyQuarterlyAccess`
 * existed to expose it) with an explicit `DEFAULT CURRENT_TIMESTAMP`
 * (deliberately without `ON UPDATE`) -- the same MariaDB legacy TIMESTAMP
 * auto-initialisation trap already found and fixed twice before
 * (`organisation_licenses.effective_from`, and proactively for
 * `organisation_administrators`/`user_capability_assignments`): `due_at`
 * was this table's own one NOT-NULL-timestamp-without-explicit-default
 * column, so MariaDB was silently attaching both `DEFAULT
 * CURRENT_TIMESTAMP` and `ON UPDATE CURRENT_TIMESTAMP` to it. Latent and
 * harmless while this table was only ever INSERTed into (slice 2); would
 * have silently corrupted `due_at` on every `certifyQuarterlyAccess`
 * completion UPDATE (which never sets `due_at` itself) the moment that
 * command existed -- caught here, before it could ship.
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
            $table->timestamp('due_at')->useCurrent();
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

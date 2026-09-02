<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_certifications` table --
 * `certifyQuarterlyAccess`'s own write target, one row per (review,
 * subject) pair (the UNIQUE index below matches the source exactly); the
 * review auto-completes once every active member of the organisation has
 * been certified (see `AccessGovernanceService::certifyQuarterlyAccess()`
 * for that count comparison).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('access_review_id')->constrained('access_reviews');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('subject_user_id')->constrained('users');
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->text('snapshot');
            $table->string('disposition', 20);
            $table->text('finding')->nullable();
            $table->timestamp('certified_at')->useCurrent();

            $table->unique(['access_review_id', 'subject_user_id'], 'access_certifications_review_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_certifications');
    }
};

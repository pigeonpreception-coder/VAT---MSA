<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `change_requests` table -- the maker-checker
 * envelope proposeChange/applyChange writes around a change to
 * `access_policies`/`feature_flags`/`platform_config`. `target_type`/
 * `target_id` is a polymorphic reference (no single FK target), matching
 * the source exactly. No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target_type', 40);
            $table->string('target_id');
            $table->text('previous_value');
            $table->text('proposed_value');
            $table->text('reason');
            $table->string('status', 20);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `audit_chain_verifications` table -- the
 * run log a not-yet-ported VerifyAuditChain command would write, walking
 * `audit_events`' own hash chain (App\Services\Audit\AuditService already
 * writes that chain; nothing yet re-verifies it end to end). No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('status', 20);
            $table->unsignedBigInteger('verified_count');
            $table->uuid('first_break_id')->nullable();
            $table->text('first_break_reason')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_verifications');
    }
};

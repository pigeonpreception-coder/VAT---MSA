<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `user_invitations` table -- an
 * identity-level, claim-token invitation flow genuinely distinct from
 * Phase 12 slice 2's `employees`/`inviteEmployee` (that table manages an
 * organisation's own staff roster; this one is the lower-level "invite
 * someone to claim a role_code via a token" primitive it and other
 * modules may build on). No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('email');
            $table->string('role_code', 60);
            $table->foreign('role_code')->references('code')->on('access_roles');
            $table->string('claim_token', 100)->unique();
            $table->string('status', 20);
            $table->foreignUuid('invited_by')->constrained('users');
            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignUuid('claimed_by_user_id')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};

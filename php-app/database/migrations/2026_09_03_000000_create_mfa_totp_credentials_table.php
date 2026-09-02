<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `mfa_totp_credentials` table -- Security fix
 * 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2): a real, server-verified
 * TOTP (RFC 6238) credential, replacing the previous client-asserted
 * assurance headers. One row per user (`user_id` itself is the primary
 * key, not a separate uuid). No command references this table yet in
 * this migration -- MFA enrollment/verification is not yet ported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_totp_credentials', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users');
            $table->string('secret_base32', 64);
            $table->string('status', 20);
            $table->unsignedBigInteger('last_used_counter')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_totp_credentials');
    }
};

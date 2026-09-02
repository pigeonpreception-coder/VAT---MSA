<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `step_up_events` table -- the server-side
 * log a verified TOTP challenge writes to, paired with
 * `mfa_totp_credentials`. No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('step_up_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('method', 30);
            $table->timestamp('verified_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_up_events');
    }
};

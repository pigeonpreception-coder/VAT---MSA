<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `registration_verifications` table -- one row per ITAS (or MANUAL_REVIEW) verification attempt against a registration application. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_application_id')->constrained('registration_applications');
            $table->string('provider', 20);
            $table->string('request_reference');
            // 30, not 20 -- 'AWAITING_PROVIDER_CONTRACT' (26 chars) is a real value (see ItasIdentityPort's doc comment).
            $table->string('status', 30);
            $table->string('response_hash', 64)->nullable();
            $table->foreignUuid('verified_taxpayer_id')->nullable()->constrained('taxpayers');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['provider', 'request_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_verifications');
    }
};

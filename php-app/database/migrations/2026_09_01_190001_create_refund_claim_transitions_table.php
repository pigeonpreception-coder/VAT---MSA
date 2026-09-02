<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `refund_claim_transitions` table -- one row per state-machine action, mirroring `audit_case_transitions`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_claim_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_claim_id')->constrained('refund_claims');
            $table->string('action', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->foreignUuid('actor_id')->constrained('users');
            $table->text('findings');
            $table->timestamp('occurred_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_claim_transitions');
    }
};

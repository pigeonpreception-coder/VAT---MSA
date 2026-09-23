<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `counterparty_trust_events` table -- the
 * append-only state-change log for a counterparty trust profile
 * (CounterpartyVerificationRequested on party creation/identity change,
 * CounterpartyTrustEvaluated on synthetic verification). Records the
 * transition and its evidence_hash, never the raw provider payload,
 * matching 05-security/issue3-counterparty-trust-boundary.md's "current
 * projections expose status ... not evidence hashes or raw authority
 * payloads" for reads, while still keeping the hash itself for this
 * append-only trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_trust_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trust_profile_id')->constrained('counterparty_trust_profiles');
            $table->string('event_type', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason_code', 60);
            $table->string('evidence_hash')->nullable();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->timestamp('occurred_at');

            $table->index(['trust_profile_id', 'occurred_at'], 'counterparty_trust_events_profile_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_trust_events');
    }
};

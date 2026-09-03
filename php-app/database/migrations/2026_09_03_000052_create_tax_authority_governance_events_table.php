<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_governance_events` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. A domain-specific event stream
 * alongside the global `audit_events` table, matching the source's own
 * dual-write in `createAuthorityOnboardingCase`/`decideAuthorityOnboardingCase`
 * (both write one `audit_events` row via `AuditService::append` and one
 * of these).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_governance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->foreignUuid('onboarding_case_id')->nullable()->constrained('tax_authority_onboarding_cases');
            $table->string('event_type', 80);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason_code', 60);
            $table->string('evidence_hash')->nullable();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_governance_events');
    }
};

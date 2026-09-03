<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_onboarding_cases` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. `PRODUCTION_ACTIVATED` is a real status
 * value in the source's own CHECK constraint but no command in this
 * module ever writes it (production activation is deliberately outside
 * this pilot's own scope, per `AuthorityGovernanceService`'s own doc
 * comment) -- kept in the enum for schema fidelity, not reachable code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_onboarding_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->enum('target_environment', ['LOCAL_STAGING', 'PRODUCTION']);
            $table->enum('status', ['SUBMITTED', 'UNDER_REVIEW', 'LOCAL_STAGING_READY', 'BLOCKED_EXTERNAL', 'REJECTED', 'PRODUCTION_ACTIVATED']);
            $table->text('purpose');
            $table->string('evidence_bundle_hash')->nullable();
            $table->string('readiness_reference')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_onboarding_cases');
    }
};

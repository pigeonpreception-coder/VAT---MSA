<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_onboarding_decisions` table
 * -- see 2026_09_03_000043_create_countries_table.php's own doc comment
 * for this module's overall context. This pilot's own
 * `AuthorityGovernanceService::decideOnboardingCase` only ever writes
 * `decision_type` LOCAL_STAGING_APPROVAL or REJECTION (see that
 * service's own doc comment) -- the other four values are kept in the
 * enum for schema fidelity with the source's own multi-stage review
 * pipeline, not reachable from this module's own command surface. The
 * source's own `CHECK (requested_by<>decided_by)` is enforced at the
 * application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_onboarding_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('onboarding_case_id')->constrained('tax_authority_onboarding_cases');
            $table->enum('decision_type', [
                'LOCAL_STAGING_APPROVAL', 'SECURITY_APPROVAL', 'PRIVACY_APPROVAL', 'LEGAL_APPROVAL',
                'INTEGRATION_APPROVAL', 'ACTIVATION_APPROVAL', 'REJECTION',
            ]);
            $table->enum('decision', ['APPROVE', 'REJECT']);
            $table->text('reason');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->constrained('users');
            $table->string('evidence_hash');
            $table->string('step_up_evidence_reference');
            $table->timestamp('occurred_at');

            $table->unique(['onboarding_case_id', 'decision_type'], 'ta_onboarding_decisions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_onboarding_decisions');
    }
};

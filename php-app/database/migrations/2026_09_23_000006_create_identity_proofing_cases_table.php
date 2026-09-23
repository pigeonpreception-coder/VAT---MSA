<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `identity_proofing_cases` table -- the
 * external identity-provider reconciliation record behind a registration
 * application's "proofing" columns. Nothing in this port (nor in source's
 * own submitRegistrationApplication) writes a row here yet -- both leave
 * it for a separate reconciliation process -- so this table exists purely
 * to support the LEFT JOIN reads in RegistrationService::list() and
 * IdentityProofingService::list(), matching source's own read-only join
 * exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_proofing_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('subject_type', ['TAXPAYER_REGISTRATION', 'USER_IDENTITY']);
            $table->string('subject_reference');
            $table->foreignUuid('registration_application_id')->nullable()->unique()->constrained('registration_applications');
            $table->string('provider');
            $table->enum('provider_environment', ['CONTRACT_PENDING', 'SYNTHETIC_TEST', 'PRODUCTION_EQUIVALENT', 'PRODUCTION']);
            $table->string('provider_reference')->nullable();
            $table->enum('status', ['PENDING_PROVIDER', 'CANDIDATE_FOUND', 'DUPLICATE_CONFIRMED', 'MISMATCH', 'MANUAL_REVIEW', 'SYNTHETIC_MATCHED', 'AUTHORITY_VERIFIED', 'REJECTED']);
            $table->unsignedInteger('confidence_bps')->default(0);
            $table->foreignUuid('matched_taxpayer_id')->nullable()->constrained('taxpayers');
            $table->string('evidence_hash', 64)->nullable();
            $table->string('reason_code');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();

            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_proofing_cases');
    }
};

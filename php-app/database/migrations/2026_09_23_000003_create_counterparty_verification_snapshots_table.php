<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `counterparty_verification_snapshots` table --
 * an immutable, append-only record of every field-by-field reconciliation
 * a counterparty trust profile has gone through (today: only the
 * SYNTHETIC_AUTHORITY provider, since AUTHORITY_VERIFIED is
 * BLOCKED -- EXTERNAL DEPENDENCY REQUIRED per
 * 05-security/issue3-counterparty-trust-boundary.md). Never updated or
 * deleted once written, matching the source's own
 * counterparty_snapshot_no_update/no_delete triggers -- this port relies
 * on App\Services\Business\CounterpartyTrustService never issuing an
 * UPDATE/DELETE against it rather than a DB-level trigger, the same
 * app-level-only convention already used for this table family's sibling
 * enum constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_verification_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trust_profile_id')->constrained('counterparty_trust_profiles');
            $table->string('provider', 40);
            $table->string('provider_environment', 30);
            $table->string('source_reference');
            $table->string('observed_vat_number')->nullable();
            $table->string('observed_tin')->nullable();
            $table->string('observed_company_registration_number')->nullable();
            $table->string('tax_registration_status', 20);
            $table->string('trust_status', 30);
            $table->unsignedInteger('confidence_bps');
            $table->text('matched_fields');
            $table->text('conflicting_fields');
            $table->string('evidence_hash');
            $table->timestamp('checked_at');
            $table->timestamp('expires_at');
            $table->foreignUuid('recorded_by')->constrained('users');

            $table->unique(['provider', 'source_reference'], 'counterparty_verification_snapshots_provider_source_unique');
            $table->index(['trust_profile_id', 'checked_at'], 'counterparty_verification_snapshots_profile_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_verification_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_federation_connections`
 * table -- see 2026_09_03_000043_create_countries_table.php's own doc
 * comment for this module's overall context. `identity_provider_id`
 * references the already-ported `identity_providers` table (Phase 6);
 * this is the one table in this new module with a foreign key into an
 * existing part of this migration. The source's own
 * `CHECK (reviewed_by IS NULL OR reviewed_by<>requested_by)` is enforced
 * at the application layer, matching this migration's own convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_federation_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            // Explicit short constraint name -- the auto-generated one
            // (table name + column + "_foreign") exceeds MySQL's 64-char
            // identifier limit for this particular table/column pairing.
            $table->uuid('identity_provider_id');
            $table->foreign('identity_provider_id', 'ta_federation_conn_idp_fk')->references('id')->on('identity_providers');
            $table->enum('environment', ['CONTRACT_PENDING', 'SYNTHETIC_TEST', 'PRODUCTION_EQUIVALENT', 'PRODUCTION']);
            $table->enum('protocol', ['UNCONFIRMED', 'OIDC', 'SAML']);
            $table->string('issuer')->nullable();
            $table->string('audience')->nullable();
            $table->string('metadata_hash')->nullable();
            $table->string('claims_contract_hash')->nullable();
            $table->string('assurance_profile')->nullable();
            $table->enum('status', [
                'CONTRACT_PENDING', 'CONFIGURATION_PENDING', 'CONFORMANCE_PENDING', 'LOCAL_STAGING_READY',
                'PRODUCTION_APPROVED', 'SUSPENDED', 'REVOKED',
            ]);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tax_authority_id', 'identity_provider_id', 'environment'], 'ta_federation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_federation_connections');
    }
};

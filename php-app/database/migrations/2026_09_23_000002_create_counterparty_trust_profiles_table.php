<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `counterparty_trust_profiles` table --
 * 05-security/issue3-counterparty-trust-boundary.md's counterparty trust
 * boundary. Exactly one current trust posture per business party
 * (`business_party_id` unique). String columns rather than the source's
 * SQLite CHECK-constrained enums, matching this port's own established
 * convention once a value set is app-level rather than DB-level policy --
 * see 2026_09_19_000001_widen_party_relationships_relationship.php's own
 * doc comment. App\Domain\Business\CounterpartyTrustEvaluator is the real
 * allow-list for `trust_status`/`*_verification_status`, and
 * App\Support\Business\CounterpartyTrustGate for `provider_environment`.
 *
 * Not organisation-scoped directly (no `organisation_id` column, matching
 * the source exactly) -- it scopes through its `business_party_id` FK to
 * business_parties, which is itself organisation-scoped. See
 * App\Models\CounterpartyTrustProfile's own doc comment for why it does
 * not use the BelongsToOrganisation trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_trust_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_party_id')->unique()->constrained('business_parties');
            $table->string('provider', 40);
            $table->string('provider_environment', 30);
            $table->string('trust_status', 30);
            $table->string('tax_registration_status', 20);
            $table->string('vat_verification_status', 20);
            $table->string('tin_verification_status', 20);
            $table->string('company_verification_status', 20);
            $table->unsignedInteger('confidence_bps')->default(0);
            $table->string('evidence_hash')->nullable();
            $table->string('source_reference')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['provider', 'source_reference'], 'counterparty_trust_profiles_provider_source_unique');
            $table->index(['trust_status', 'expires_at'], 'counterparty_trust_profiles_status_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_trust_profiles');
    }
};

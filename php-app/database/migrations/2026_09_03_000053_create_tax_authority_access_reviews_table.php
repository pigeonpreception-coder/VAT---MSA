<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_access_reviews` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. Genuinely distinct from the
 * organisation-scoped `access_reviews` table Phase 12 slice 4 already
 * ported (`App\Services\AccessGovernance\AccessGovernanceService`) --
 * this one gates Authority Governance decisions specifically (see
 * `AuthorityGovernanceService::decideOnboardingCase`'s own
 * `currentAuthorityReview` check), not organisation quarterly access
 * certification. `review_type` is a single-value CHECK in the source
 * (`review_type='QUARTERLY'`), kept as a plain string column here rather
 * than an `enum` of one value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_access_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->string('review_type', 20)->default('QUARTERLY');
            $table->date('period_start');
            $table->timestamp('due_at');
            $table->enum('status', ['OPEN', 'COMPLETED', 'OVERDUE']);
            $table->foreignUuid('owner_id')->constrained('users');
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tax_authority_id', 'review_type', 'period_start'], 'ta_access_reviews_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_access_reviews');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_units` table -- see
 * 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. `parent_unit_id` is a nullable
 * self-reference (a unit's own governed hierarchy); the source's own
 * `CHECK (parent_unit_id IS NULL OR parent_unit_id<>id)` is enforced at
 * the application layer (AuthorityGovernanceValidator), matching this
 * migration's own established convention of not relying on MySQL CHECK
 * constraints for cross-column business rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->foreignUuid('parent_unit_id')->nullable()->constrained('tax_authority_units');
            $table->string('code', 60);
            $table->string('name');
            $table->enum('unit_type', ['HEAD_OFFICE', 'DIRECTORATE', 'DIVISION', 'REGION', 'OFFICE', 'TEAM']);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tax_authority_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_units');
    }
};

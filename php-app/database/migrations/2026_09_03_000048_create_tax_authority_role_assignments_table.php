<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_role_assignments` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. The source's own
 * `CHECK (requested_by<>approved_by)` (self-approval denial) is enforced
 * at the application layer, matching `tax_authority_units.parent_unit_id`'s
 * own precedent above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->foreignUuid('authority_unit_id')->nullable()->constrained('tax_authority_units');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role_code', 60);
            $table->foreign('role_code')->references('code')->on('tax_authority_role_definitions');
            $table->text('scope');
            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'REVOKED', 'EXPIRED']);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('approved_by')->constrained('users');
            $table->string('approval_reference');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tax_authority_id', 'user_id', 'role_code', 'authority_unit_id'], 'ta_role_assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_role_assignments');
    }
};

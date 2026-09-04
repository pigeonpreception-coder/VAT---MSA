<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_administrators` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. The scope-defining table for this
 * whole module: `AuthorityGovernanceService::getSnapshot`'s own
 * "assigned Tax Authority administration scope" is exactly the set of
 * authorities a user has an ACTIVE row here for, within its
 * `effective_from`/`effective_to` window -- an actor with no row here
 * sees no governance data at all, matching the source's own
 * `AccessDeniedError` for that case. `appointed_by` carries no FK in the
 * source (a plain `TEXT` column, possibly an external/manual reference
 * rather than always another `app_users` row) -- reproduced as a plain
 * string here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_administrators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_authority_id')->constrained('tax_authorities');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('status', 20);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('appointed_by');
            $table->string('approval_reference');

            $table->unique(['tax_authority_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_administrators');
    }
};

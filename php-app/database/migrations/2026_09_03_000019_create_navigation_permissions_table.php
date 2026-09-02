<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `navigation_permissions` table -- a
 * per-item ABAC-style override the source only ever seeds two rows into
 * and never reads: a full-repo grep before writing this migration found
 * no reference to it anywhere outside `db/runtime.ts`/`db/schema.ts`,
 * including in `getEffectiveNavigation`/`getNavigationItemActions`
 * themselves (Phase 12 slice 3, already fully ported in
 * App\Services\Navigation\NavigationService, which does not consult this
 * table -- matching the source's own behaviour). Built purely for schema
 * parity, not because any command reads or writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('navigation_item_id')->constrained('navigation_items');
            $table->string('policy_key', 100);
            $table->string('effect', 20);
            $table->text('safe_restriction_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_permissions');
    }
};

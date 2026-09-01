<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `navigation_items` table -- the actual
 * clickable leaf rows. `feature_key`/`capability` are the two optional
 * gates `getEffectiveNavigation`'s own row filter checks alongside
 * `required_permission` -- see NavigationService::rowAllowed().
 * `required_permission` deliberately has no FK to `access_permissions`:
 * the source's own schema doesn't constrain it either (confirmed against
 * db/runtime.ts), and a handful of seeded rows below use permission codes
 * that are genuinely valid but not every one of the 22 built-in roles'
 * own grant sets -- exactly the kind of per-item narrowing this whole
 * table exists to express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table) {
            $table->string('id', 60)->primary();
            $table->string('workspace_id', 40);
            $table->foreign('workspace_id')->references('id')->on('navigation_workspaces');
            $table->string('folder_id', 60);
            $table->foreign('folder_id')->references('id')->on('navigation_folders');
            $table->string('item_key', 60)->unique();
            $table->string('label');
            $table->string('href');
            $table->string('feature_key', 40)->nullable();
            $table->string('capability', 20)->nullable();
            $table->string('required_permission', 60);
            $table->unsignedInteger('sort_order');
            $table->string('status', 20);
            $table->string('classification', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `navigation_workspaces` table -- a fixed,
 * seed-only catalogue of top-level workspace groupings (Home, Sales,
 * VAT & Tax Management, ...). String `id` (not UUID) matches the source's
 * own natural-key rows ('nav-home', 'nav-sales', ...), reused verbatim by
 * `navigation_folders`/`navigation_items` FKs and by NavigationSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_workspaces', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('workspace_key', 40)->unique();
            $table->string('label');
            $table->text('description');
            $table->unsignedInteger('sort_order');
            $table->string('status', 20);
            $table->string('classification', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_workspaces');
    }
};

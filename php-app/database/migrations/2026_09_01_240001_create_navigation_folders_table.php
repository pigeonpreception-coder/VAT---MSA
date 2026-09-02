<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `navigation_folders` table. `parent_folder_id`
 * is deliberately self-referencing but nullable-without-FK-constraint --
 * matching the source's own plain `TEXT` column (no `REFERENCES` clause on
 * it there either) -- `getNavigationChildren`'s own folder drill-down is
 * the only reader that walks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_folders', function (Blueprint $table) {
            $table->string('id', 60)->primary();
            $table->string('workspace_id', 40);
            $table->foreign('workspace_id')->references('id')->on('navigation_workspaces');
            $table->string('parent_folder_id', 60)->nullable();
            $table->string('folder_key', 60);
            $table->string('label');
            $table->unsignedInteger('sort_order');
            $table->string('status', 20);

            $table->unique(['workspace_id', 'folder_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_folders');
    }
};

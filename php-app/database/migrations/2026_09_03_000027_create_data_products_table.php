<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `data_products` table -- the analytics
 * layer sitting on top of `report_definitions`, the anchor
 * `data_product_lineage`/`metrics`/`analytics_model_runs`/
 * `data_product_snapshots` build on. No command references this table
 * yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description');
            $table->foreignUuid('source_report_definition_id')->constrained('report_definitions');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_products');
    }
};

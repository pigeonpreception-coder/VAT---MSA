<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `analytics_model_runs` table -- a
 * `data_products` row's own model execution over a given `report_runs`
 * row, the anchor `data_product_snapshots` builds on. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_model_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_product_id')->constrained('data_products');
            $table->foreignUuid('report_run_id')->constrained('report_runs');
            $table->string('status', 20);
            $table->text('model_output');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_model_runs');
    }
};

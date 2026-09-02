<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `metrics` table -- a named, thresholded
 * field within a `data_products` row that `analytics_anomaly_candidates`
 * checks snapshot-over-snapshot. No command references this table yet in
 * this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->foreignUuid('data_product_id')->constrained('data_products');
            $table->string('field', 100);
            $table->string('unit', 30);
            $table->string('status', 20);
            $table->double('anomaly_threshold_pct')->default(25);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};

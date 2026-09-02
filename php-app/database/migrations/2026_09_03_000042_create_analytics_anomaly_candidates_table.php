<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `analytics_anomaly_candidates` table -- a
 * `metrics` row flagged against a `data_product_snapshots` row for
 * exceeding its own `anomaly_threshold_pct`. The final table in this
 * batch's dependency chain (data_products -> ... -> data_product_snapshots
 * -> this). No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_anomaly_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_product_snapshot_id')->constrained('data_product_snapshots');
            $table->string('metric_code', 60);
            $table->double('previous_value');
            $table->double('current_value');
            $table->double('pct_change');
            $table->double('threshold_pct');
            $table->timestamp('detected_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_anomaly_candidates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `data_product_snapshots` table -- a
 * published, versioned output of an `analytics_model_runs` row, chained
 * via `previous_snapshot_id` the same way `document_metadata`'s own
 * `supersedes_document_id` chain works. The anchor
 * `analytics_anomaly_candidates` builds on. No command references this
 * table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_product_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_product_id')->constrained('data_products');
            $table->foreignUuid('model_run_id')->constrained('analytics_model_runs');
            $table->longText('snapshot');
            $table->foreignUuid('previous_snapshot_id')->nullable()->constrained('data_product_snapshots');
            $table->foreignUuid('published_by')->constrained('users');
            $table->timestamp('published_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_product_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `data_product_lineage` table -- the
 * source-provenance trail a `data_products` row records. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_product_lineage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_product_id')->constrained('data_products');
            $table->string('source_type', 40);
            $table->string('source_id');
            $table->string('source_label');
            $table->timestamp('recorded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_product_lineage');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `quotation_lines` table. `product_id` carries
 * no FK here -- the source references `products(id)`, but Phase 10's
 * products/inventory sub-slice has not been migrated yet (see
 * docs/MIGRATION_MATRIX.md); a product-linked line is accepted and stored
 * as an opaque identifier without catalog verification until that lands, a
 * documented gap rather than a silent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quotation_id')->constrained('quotations');
            $table->unsignedInteger('line_number');
            $table->uuid('product_id')->nullable();
            $table->text('description');
            $table->bigInteger('quantity_micros');
            $table->string('unit_code', 12);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('net_amount_cents');
            $table->enum('tax_category', ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUT_OF_SCOPE']);
            $table->integer('tax_rate_bps');
            $table->bigInteger('tax_amount_cents');

            $table->unique(['quotation_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `products` table -- Module 5 Phase D CreateProduct unstuck this from seed-only data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('sku', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_code', 12);
            $table->enum('tax_category', ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUT_OF_SCOPE']);
            $table->integer('tax_rate_bps');
            $table->bigInteger('sales_price_cents');
            $table->bigInteger('cost_price_cents');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'sku'], 'products_org_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `inventory_balances` table. The source's
 * `CHECK (quantity_micros >= 0)` is enforced here via an unsigned column
 * type instead of an explicit CHECK constraint -- functionally equivalent
 * (a negative write fails under MySQL strict mode) and avoids depending on
 * MariaDB/MySQL CHECK-constraint enforcement differences across versions.
 * `version` is optimistic-concurrency metadata carried over from the
 * source verbatim, not currently read by any ported service method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('product_id')->constrained('products');
            $table->unsignedBigInteger('quantity_micros')->default(0);
            $table->bigInteger('average_cost_cents')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};

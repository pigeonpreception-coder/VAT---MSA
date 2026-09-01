<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `stock_movements` table. `reference_type`+`reference_id` under the UNIQUE(organisation_id, reference_type, reference_id) constraint is what keeps a TransferStock's two legs (TRANSFER_OUT/TRANSFER_IN) distinct rows sharing one transfer id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('product_id')->constrained('products');
            $table->enum('movement_type', ['RECEIPT', 'ISSUE', 'TRANSFER_IN', 'TRANSFER_OUT', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT']);
            $table->bigInteger('quantity_micros');
            $table->bigInteger('unit_cost_cents');
            $table->string('reference_type', 40);
            $table->uuid('reference_id');
            $table->text('reason');
            $table->timestamp('occurred_at');
            $table->foreignUuid('actor_id')->constrained('users');

            $table->unique(['organisation_id', 'reference_type', 'reference_id'], 'stock_movements_org_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

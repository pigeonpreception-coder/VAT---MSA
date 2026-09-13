<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/schema.ts's `logisticsDeliveries` table -- Operations >
 * Logistics Module (NamRA e-VAT MS master prompt section 16E). A delivery
 * always references the tax invoice (or POS sale) it fulfils via
 * reference_type/reference_id; `vehicle_asset_id` optionally cites a
 * MOVABLE row in `fixed_assets` (validated in the service layer, not by a
 * class-conditional FK MySQL cannot express).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('delivery_number', 40);
            $table->enum('reference_type', ['INVOICE', 'POS_SALE', 'OTHER']);
            $table->string('reference_id', 80)->nullable();
            $table->string('origin', 300);
            $table->string('destination', 300);
            $table->foreignUuid('vehicle_asset_id')->nullable()->constrained('fixed_assets');
            $table->enum('status', ['PENDING', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'])->default('PENDING');
            $table->string('notes', 500)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'delivery_number']);
            $table->index(['organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_deliveries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/schema.ts's `fixedAssets` table -- Operations > Immovable
 * Asset Management and Movable Asset Management (NamRA e-VAT MS master
 * prompt section 16E). One table serves both pages via the `asset_class`
 * discriminator, matching lib/domain/fixed-asset.ts's own design (see that
 * file's doc comment): the two are the same lifecycle with a different
 * category enum, not two separate concepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->enum('asset_class', ['IMMOVABLE', 'MOVABLE']);
            $table->string('asset_code', 40);
            $table->string('category', 20);
            $table->string('description', 300);
            $table->string('serial_or_registration_number', 80)->nullable();
            $table->string('location_or_address', 300);
            $table->foreignUuid('custodian_employee_id')->nullable()->constrained('employees');
            $table->date('acquisition_date');
            $table->bigInteger('acquisition_cost_cents');
            $table->bigInteger('current_value_cents')->nullable();
            $table->enum('status', ['ACTIVE', 'UNDER_MAINTENANCE', 'DISPOSED'])->default('ACTIVE');
            $table->string('disposal_reason', 500)->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'asset_code']);
            $table->index(['organisation_id', 'asset_class', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};

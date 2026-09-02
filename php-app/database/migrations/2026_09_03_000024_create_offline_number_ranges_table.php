<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `offline_number_ranges` table -- the
 * pre-allocated invoice-numbering range each `offline_devices` row draws
 * from while disconnected. No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_number_ranges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offline_device_id')->constrained('offline_devices');
            $table->string('document_type', 40);
            $table->string('prefix', 20);
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('next_number');
            $table->string('status', 20);
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_to')->useCurrent();

            $table->unique(['offline_device_id', 'document_type', 'prefix'], 'offline_number_ranges_device_type_prefix_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_number_ranges');
    }
};

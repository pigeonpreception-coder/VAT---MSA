<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `offline_sync_batches` table -- a signed,
 * hash-chained batch of documents an offline device uploads on
 * reconnection, the anchor `offline_conflicts` builds on. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offline_device_id')->constrained('offline_devices');
            $table->string('client_batch_id', 100);
            $table->unsignedBigInteger('sequence_from');
            $table->unsignedBigInteger('sequence_to');
            $table->string('previous_batch_hash', 64)->nullable();
            $table->string('batch_hash', 64);
            $table->text('signature');
            $table->unsignedInteger('document_count');
            $table->string('status', 20);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unique(['offline_device_id', 'client_batch_id']);
            $table->unique(['offline_device_id', 'sequence_from', 'sequence_to'], 'offline_sync_batches_device_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_batches');
    }
};

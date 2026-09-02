<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `offline_devices` table -- Module 22's
 * offline-invoicing device enrolment, the anchor
 * `offline_number_ranges`/`offline_sync_batches`/`offline_conflicts`
 * build on. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('device_code', 60);
            $table->string('display_name');
            $table->string('public_key_reference')->nullable();
            $table->string('certificate_fingerprint')->nullable();
            $table->string('status', 20);
            $table->string('enrolment_status', 20);
            $table->unsignedBigInteger('last_accepted_sequence')->default(0);
            $table->string('last_batch_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'device_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_devices');
    }
};

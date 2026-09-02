<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `offline_conflicts` table -- a document
 * within an `offline_sync_batches` row that collided with an
 * already-existing resource on ingest. No command references this table
 * yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offline_sync_batch_id')->constrained('offline_sync_batches');
            $table->string('conflict_type', 40);
            $table->string('source_document_id');
            $table->string('existing_resource_id')->nullable();
            $table->string('status', 20);
            $table->string('resolution', 30)->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_conflicts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `sync_jobs` table -- the source's own
 * platform snapshot reads it (`getPlatformSnapshot`, not yet ported). No
 * command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_connection_id')->constrained('integration_connections');
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->string('job_type', 40);
            $table->string('direction', 20);
            $table->string('status', 20);
            $table->string('cursor')->nullable();
            $table->unsignedBigInteger('records_read')->default(0);
            $table->unsignedBigInteger('records_written')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
    }
};

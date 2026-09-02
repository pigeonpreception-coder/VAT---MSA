<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `report_exports` table -- a watermarked,
 * step-up-gated export of a `report_runs` row's own output document. No
 * command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_run_id')->constrained('report_runs');
            $table->foreignUuid('document_id')->constrained('document_metadata');
            $table->string('status', 20);
            $table->boolean('requires_step_up');
            $table->text('watermark');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('expires_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `report_runs` table -- one execution of a
 * `report_definitions` row, the anchor `report_exports`/
 * `analytics_model_runs` build on. No command references this table yet
 * in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions');
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->nullable()->constrained('taxpayers');
            $table->text('parameters');
            $table->string('status', 20);
            $table->unsignedBigInteger('row_count')->nullable();
            $table->text('result_summary')->nullable();
            $table->foreignUuid('output_document_id')->nullable()->constrained('document_metadata');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('scope_snapshot')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};

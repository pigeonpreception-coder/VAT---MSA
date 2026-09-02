<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `communication_threads` table -- Module 6
 * Phase C. `UNIQUE(related_resource_type, related_resource_id)` is what
 * makes "the conversation about case X" unambiguous: SendNotice refuses to
 * open a second thread for a reference that already has one; a follow-up
 * belongs in Respond instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->enum('related_resource_type', ['AUDIT_CASE', 'REFUND_CLAIM', 'RECONCILIATION_EXCEPTION']);
            $table->string('related_resource_id');
            $table->string('subject');
            $table->string('classification', 20);
            $table->string('status', 20);
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at')->useCurrent();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_reason')->nullable();

            $table->unique(['related_resource_type', 'related_resource_id'], 'communication_threads_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_threads');
    }
};

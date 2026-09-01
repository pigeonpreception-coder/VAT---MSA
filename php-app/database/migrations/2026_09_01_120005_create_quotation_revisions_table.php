<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `quotation_revisions` table -- a hash-chained
 * (via snapshot_hash, chained to the previous revision's hash inside the
 * stored snapshot itself, see App\Services\Business\QuotationService's own
 * quotationRevisionRecord) append-only history of every CREATE/EDIT/SEND
 * transition, distinct from and in addition to the global audit_events log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quotation_id')->constrained('quotations');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->unsignedInteger('revision_number');
            $table->enum('action', ['CREATE', 'EDIT', 'SEND']);
            $table->string('status', 20);
            $table->string('snapshot_hash', 64);
            $table->longText('snapshot');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['quotation_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_revisions');
    }
};

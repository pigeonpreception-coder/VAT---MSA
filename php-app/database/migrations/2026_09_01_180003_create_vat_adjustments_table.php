<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `vat_adjustments` table. The source's
 * `evidence_document_id` references `document_metadata`, a table this
 * migration has not built yet (still-deferred, see docs/MIGRATION_MATRIX.md).
 * Rather than a broken/unenforceable FK or a silently-ignored column, this
 * stays a plain nullable string with no constraint, and
 * VatLifecycleService rejects any adjustment submission that actually
 * supplies one with a clear scoping error -- the same deferral pattern
 * already used for REFUND_CLAIM-referenced notices in
 * CommunicationService::resolveCaseReference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vat_period_id')->constrained('vat_periods');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('adjustment_type', 20);
            $table->string('direction', 10);
            $table->bigInteger('amount_cents');
            $table->string('reason_code', 40);
            $table->text('explanation');
            $table->string('evidence_document_id')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_adjustments');
    }
};

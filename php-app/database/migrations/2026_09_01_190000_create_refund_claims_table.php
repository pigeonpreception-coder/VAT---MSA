<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `refund_claims` table -- one row per VAT
 * return version (`UNIQUE(vat_return_version_id)`), the real workflow the
 * VAT-return-generation prerequisite (tax_rule_sets/vat_periods/
 * vat_return_versions -- see that migration's own doc comments) was built
 * to unblock. `claim_snapshot`/`claim_snapshot_hash` are written once by
 * RefundService::request and never touched again by any later transition
 * -- see that service's own doc comment for why the claim's evidentiary
 * basis must stay frozen even if the underlying return is later corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('claim_number')->unique();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->foreignUuid('vat_return_version_id')->unique()->constrained('vat_return_versions');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 30);
            $table->string('evidence_status', 40);
            $table->string('risk_tier', 20);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('payment_instruction_id')->nullable();
            $table->string('resume_status', 30)->nullable();
            $table->bigInteger('offset_amount_cents')->default(0);
            $table->bigInteger('net_payable_cents')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->text('claim_snapshot')->nullable();
            $table->string('claim_snapshot_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_claims');
    }
};

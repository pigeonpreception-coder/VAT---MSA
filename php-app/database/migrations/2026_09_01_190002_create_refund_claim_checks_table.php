<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `refund_claim_checks` table -- the fixed, explainable eligibility/advisory check battery RefundService::request evaluates once and freezes alongside the claim snapshot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_claim_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_claim_id')->constrained('refund_claims');
            $table->string('check_code', 40);
            $table->string('status', 20);
            $table->text('rationale');
            $table->timestamp('evaluated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_claim_checks');
    }
};

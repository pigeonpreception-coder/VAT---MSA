<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `payment_instructions` table -- the actual
 * payment-rail record RefundService's own `PAYMENT_AUTHORISATION` stage
 * would create (`refund_claims.payment_instruction_id` is already a plain
 * string column, not a foreign key, matching the source exactly -- see
 * that migration's own note). A full-repo grep before writing this
 * migration confirmed no command in the TypeScript source writes to this
 * table either; `payments:read`/`payments:record` permissions already
 * exist in App\Support\Access\Permissions for whichever future command
 * needs them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_claim_id')->nullable()->constrained('refund_claims');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('beneficiary_reference_masked');
            $table->string('provider', 40);
            $table->string('status', 20);
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key', 128);
            $table->foreignUuid('approved_by')->constrained('users');
            $table->timestamp('approved_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unique(['provider', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_instructions');
    }
};

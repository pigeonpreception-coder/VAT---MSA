<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `quotations` table -- DRAFT->ISSUED->ACCEPTED->CONVERTED (or REJECTED/EXPIRED) lifecycle, see App\Domain\Business\BusinessValidator::evaluateQuotationLifecycle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->foreignUuid('customer_party_id')->constrained('business_parties');
            $table->string('quotation_number');
            $table->string('currency', 3);
            $table->date('issue_date');
            $table->date('valid_until');
            $table->string('status', 20);
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            // No FK -- the source's own schema leaves this a plain TEXT reference to `invoices(id)`
            // (invoice certification, Phase 9, is a cross-module write outside this table's own
            // transaction), matched exactly rather than tightened.
            $table->uuid('converted_invoice_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'quotation_number'], 'quotations_org_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};

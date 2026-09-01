<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `vat_transactions` table -- PostTransaction's own record, linking a correction back to the original's transaction for GetTransactionTimeline. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->enum('transaction_type', ['CERTIFICATION', 'CORRECTION']);
            $table->uuid('reference_transaction_id')->nullable();
            $table->foreign('reference_transaction_id')->references('id')->on('vat_transactions');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_transactions');
    }
};

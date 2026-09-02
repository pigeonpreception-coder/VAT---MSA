<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `ledger_entries` table -- the OUTPUT_VAT/INPUT_VAT entries every certified invoice posts, grouped by transaction_id (not itself an FK -- see vat_transactions). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->enum('entry_type', ['OUTPUT_VAT', 'INPUT_VAT']);
            $table->enum('direction', ['DEBIT', 'CREDIT']);
            $table->bigInteger('amount_cents');
            $table->string('period', 7); // YYYY-MM
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index(['taxpayer_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

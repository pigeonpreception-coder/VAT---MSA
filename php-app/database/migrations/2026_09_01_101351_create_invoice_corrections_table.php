<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `invoice_corrections` table -- links a CREDIT_NOTE/DEBIT_NOTE to the original invoice it corrects. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('original_invoice_id')->constrained('invoices');
            $table->foreignUuid('correction_invoice_id')->unique()->constrained('invoices');
            $table->enum('correction_type', ['CREDIT_NOTE', 'DEBIT_NOTE']);
            $table->string('reason_code')->nullable();
            $table->text('reason');
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_corrections');
    }
};

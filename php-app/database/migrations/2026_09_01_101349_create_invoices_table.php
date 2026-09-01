<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `invoices` table -- the CREATE->VALIDATE->AUTHORIZE->SIGN->SUBMIT->ACKNOWLEDGE->RECORD->AUDIT pipeline's central record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number');
            $table->enum('document_type', ['TAX_INVOICE', 'SIMPLIFIED_TAX_INVOICE', 'SELF_BILLED_INVOICE', 'CREDIT_NOTE', 'DEBIT_NOTE']);
            $table->string('source_system');
            $table->string('source_document_id');
            $table->foreignUuid('supplier_taxpayer_id')->constrained('taxpayers');
            $table->string('supplier_name');
            $table->string('supplier_vat_number');
            $table->foreignUuid('customer_taxpayer_id')->nullable()->constrained('taxpayers');
            $table->string('customer_name');
            $table->string('customer_vat_number')->nullable();
            $table->date('issue_date');
            $table->string('currency', 3);
            $table->bigInteger('line_net_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->string('status', 20);
            $table->string('risk_level', 10);
            $table->string('payload_hash', 64);
            $table->uuid('transaction_id');
            $table->uuid('certificate_id')->unique();
            $table->string('verification_token')->unique();
            // ->useCurrent() on both -- MariaDB strict mode allows only one TIMESTAMP NOT NULL
            // column to lack an explicit default (see the identity-core migration's own note);
            // application code always sets both explicitly regardless.
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('certified_at')->useCurrent();

            $table->unique(['supplier_taxpayer_id', 'source_system', 'source_document_id'], 'invoices_supplier_source_unique');
            $table->unique(['supplier_taxpayer_id', 'invoice_number'], 'invoices_supplier_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

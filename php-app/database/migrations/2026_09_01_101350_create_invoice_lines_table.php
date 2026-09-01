<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `invoice_lines` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->unsignedInteger('line_number');
            $table->text('description');
            $table->string('quantity'); // decimal string, matching the source's own micros-scaled text storage
            $table->string('unit_code', 20);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('net_amount_cents');
            $table->integer('tax_rate_bps');
            $table->enum('tax_category', ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUTSIDE_SCOPE', 'REVERSE_CHARGE', 'OTHER']);
            $table->bigInteger('tax_amount_cents');
            $table->foreignUuid('vat_rule_id')->nullable()->constrained('vat_rules');

            $table->unique(['invoice_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};

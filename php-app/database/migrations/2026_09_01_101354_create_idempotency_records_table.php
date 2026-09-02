<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `idempotency_records` table -- the
 * Idempotency-Key replay guard for InvoiceService::submit (Module 2 Phase E
 * in the source). Not itself sufficient against a true concurrent race
 * (see InvoiceService::submit's own note); it is the source of truth this
 * response reads back regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->foreignUuid('response_invoice_id')->constrained('invoices');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `reconciliation_exceptions` table. Note (Sec 14 /
 * SECURITY_GAP_ASSESSMENT.md item #6 in the source): this table's own
 * `taxpayer_id` column IS the tenant dimension every reconciliation query
 * must filter on -- never read/write this without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('taxpayer_id')->nullable()->constrained('taxpayers');
            $table->string('exception_type', 30);
            $table->string('severity', 10);
            $table->string('status', 20);
            $table->text('summary');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('assigned_officer_id')->nullable()->constrained('users');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->text('resolution_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
    }
};

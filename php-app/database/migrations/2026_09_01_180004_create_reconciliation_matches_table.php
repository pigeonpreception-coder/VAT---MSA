<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `reconciliation_matches` table -- distinct
 * from Phase 9's `reconciliation_exceptions` (UNREGISTERED_BUYER-style
 * invoice flags). This table records the evidence VatLifecycleService's own
 * getVatLifecycleSnapshot dashboard reads (matched/unmatched counts per
 * period); like `tax_rule_sets`/`vat_periods`, the source has no
 * application write path for it either (its own reconciliation-repository.ts
 * is a separate, still-unmigrated module -- lib/data/reconciliation-
 * repository.ts -- tracked as a further gap, not silently dropped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->foreignUuid('vat_period_id')->nullable()->constrained('vat_periods');
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->string('match_type', 40);
            $table->integer('confidence_bps');
            $table->string('status', 30);
            $table->text('evidence');
            $table->foreignUuid('reconciled_by')->nullable()->constrained('users');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invoice_id', 'taxpayer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_matches');
    }
};

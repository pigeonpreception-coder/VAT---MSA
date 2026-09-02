<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `vat_return_versions` table -- the real
 * prerequisite Phase 11's refund slice was blocked on. `parent_version_id`
 * has no FK in the source either (a plain TEXT column, not
 * self-referencing) and is kept that way here for fidelity.
 * `output_tax_cents`/`input_tax_cents`/`adjustment_cents`/
 * `net_payable_cents` are signed (a refund position is a negative net
 * payable), so these stay plain `bigInteger`, not unsigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_return_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vat_period_id')->constrained('vat_periods');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->unsignedInteger('version_number');
            $table->uuid('parent_version_id')->nullable();
            $table->foreignUuid('tax_rule_set_id')->constrained('tax_rule_sets');
            $table->bigInteger('output_tax_cents');
            $table->bigInteger('input_tax_cents');
            $table->bigInteger('adjustment_cents');
            $table->bigInteger('net_payable_cents');
            $table->string('status', 20);
            $table->string('ledger_snapshot_hash', 64);
            $table->foreignUuid('generated_by')->constrained('users');
            $table->timestamp('generated_at')->useCurrent();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['vat_period_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_return_versions');
    }
};

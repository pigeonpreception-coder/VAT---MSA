<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_rule_sets` table -- the VAT-return-
 * generation prerequisite Phase 9 deferred (see docs/MIGRATION_MATRIX.md's
 * Phase 9/11 rows). The source has no application code path that ever
 * writes this table: every row is provisioned out of band (its own
 * db/runtime.ts seed data is the only writer in the whole codebase, grepped
 * and confirmed before writing this migration) -- a jurisdiction's rule set
 * is authority-controlled configuration, not a taxpayer- or officer-facing
 * command. This port therefore also has no "create tax rule set" endpoint;
 * DemoSeeder provisions the same seed row the source ships, and
 * VatLifecycleService only ever reads this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rule_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('jurisdiction', 10);
            $table->string('version', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('standard_rate_bps');
            $table->string('legal_authority_reference')->nullable();
            $table->string('status', 30);
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['jurisdiction', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rule_sets');
    }
};

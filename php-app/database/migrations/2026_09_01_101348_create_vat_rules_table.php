<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `vat_rules` table -- the authority-approved rate registry lib/data/vat-rule-repository.ts's getApplicableVatRule resolves against. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('tax_category', ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUTSIDE_SCOPE', 'REVERSE_CHARGE', 'OTHER']);
            $table->string('country', 3)->default('NA');
            $table->unsignedInteger('rate_bps');
            $table->string('status', 20);
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('proposed_by');
            $table->timestamp('proposed_at');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->text('proposal_reason');
            $table->uuid('superseded_by')->nullable();
            $table->foreign('superseded_by')->references('id')->on('vat_rules');

            $table->unique(['tax_category', 'country', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `vat_periods` table. Grepped across lib/ and
 * confirmed: the source itself has no "open a VAT period" command anywhere
 * in its application code -- periods are provisioned out of band (its own
 * seed data is the only writer), presumably by a still-undocumented ops
 * process outside this codebase's own scope. This port mirrors that
 * honestly: VatLifecycleService only ever reads and closes/locks periods
 * (status transitions driven by return-approval), never creates one; tests
 * provision fixture periods directly, exactly as the source's own demo seed
 * does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('period_code', 7); // YYYY-MM
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('status', 20);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('close_requested_by')->nullable()->constrained('users');
            $table->timestamp('close_requested_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['taxpayer_id', 'period_code']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_periods');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `accounting_periods` table -- Module 5 Phase C
 * ClosePeriod. Periods are implicit and open by default: a row only exists
 * once a period has actually been closed (see App\Services\Business\
 * AccountingService::assertPeriodOpen), there is no separate "open/create a
 * period" command in the source either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('period_code', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20);
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'period_code'], 'accounting_periods_org_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};

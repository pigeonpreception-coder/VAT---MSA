<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `tax_obligations` table -- Module 3 Phase D CreateObligation/MarkSatisfied. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('obligation_type', 50);
            $table->string('period_code', 7);
            $table->date('due_date');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('source_system', 40);
            $table->string('source_reference')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['taxpayer_id', 'obligation_type', 'period_code'], 'tax_obligations_taxpayer_type_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_obligations');
    }
};

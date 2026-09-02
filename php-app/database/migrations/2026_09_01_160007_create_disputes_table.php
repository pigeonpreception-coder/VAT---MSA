<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `disputes` table. `disputed_resource_id` carries no FK -- it references one of four different resource types (AUDIT_FINDING/VAT_RETURN/REFUND_DECISION/OBLIGATION), never validated against any single table by the source either. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dispute_number')->unique();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->foreignUuid('audit_case_id')->nullable()->constrained('audit_cases');
            $table->enum('disputed_resource_type', ['AUDIT_FINDING', 'VAT_RETURN', 'REFUND_DECISION', 'OBLIGATION']);
            $table->string('disputed_resource_id');
            $table->text('grounds');
            $table->bigInteger('disputed_amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->foreignUuid('filed_by')->constrained('users');
            $table->foreignUuid('assigned_officer_id')->nullable()->constrained('users');
            $table->timestamp('filed_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};

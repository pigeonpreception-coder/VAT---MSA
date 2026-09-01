<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `risk_indicators` table -- Module 4 Phases
 * A-B. `subject_type`/`subject_id` are always 'TAXPAYER'/taxpayer_id in
 * this port (the only subject type the source's own rule catalogue ever
 * raises); kept as separate generic columns matching the source's schema
 * rather than collapsed, since a future rule could target a different
 * subject type without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->string('indicator_code', 60);
            $table->unsignedInteger('score_bps');
            $table->string('severity', 10);
            $table->text('rationale');
            $table->string('rule_version', 40);
            $table->string('decision_effect', 20);
            $table->string('status', 20);
            $table->timestamp('detected_at')->useCurrent();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('assigned_officer_id')->nullable()->constrained('users');
            $table->foreignUuid('escalated_case_id')->nullable()->constrained('audit_cases');

            $table->unique(['subject_type', 'subject_id', 'indicator_code', 'rule_version'], 'risk_indicators_subject_indicator_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_indicators');
    }
};

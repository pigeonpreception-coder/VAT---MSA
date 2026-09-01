<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `audit_case_transitions` table -- every case status change writes one row here (exactly what GetCaseTimeline reads back), never just flips the status column in place. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_case_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id')->constrained('audit_cases');
            $table->string('action', 20);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->foreignUuid('actor_id')->constrained('users');
            $table->text('reason');
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_case_transitions');
    }
};

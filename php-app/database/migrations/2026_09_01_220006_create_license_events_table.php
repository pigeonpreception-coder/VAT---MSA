<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `license_events` table -- every state
 * transition and plan change, real application-written history (unlike
 * this migration's other new tables). `authority` stores the acting
 * actor's id but, matching the source's own schema exactly, carries no FK
 * to `users` (every other actor-id column elsewhere in this codebase does)
 * -- kept faithful rather than "corrected", since nothing about this
 * table's own purpose depends on that constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_license_id')->constrained('organisation_licenses');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('event_type', 60);
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->uuid('authority');
            $table->text('reason');
            $table->timestamp('occurred_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `user_capability_assignments` table.
 * `effective_from` is given an explicit `DEFAULT CURRENT_TIMESTAMP`
 * (`->useCurrent()`) up front for the same proactive reason as
 * `organisation_administrators.effective_from` -- see that migration's
 * own doc comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capability_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('capability', 20);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->foreignUuid('assigned_by')->constrained('users');

            $table->unique(['organisation_id', 'user_id', 'capability'], 'user_capability_assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capability_assignments');
    }
};

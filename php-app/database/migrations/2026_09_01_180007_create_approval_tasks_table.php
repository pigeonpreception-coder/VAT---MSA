<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `approval_tasks` table -- the generic
 * maker-checker queue. `resource_id` is polymorphic (VAT_ADJUSTMENT or
 * VAT_RETURN_VERSION today, per resource_type) exactly as in the source, so
 * it stays a plain string, not an FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('domain', 30);
            $table->string('resource_type', 40);
            $table->string('resource_id');
            $table->string('requested_action', 60);
            $table->string('risk_tier', 20);
            $table->string('status', 20);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('assigned_role', 40);
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();

            $table->index(['status', 'assigned_role', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_tasks');
    }
};

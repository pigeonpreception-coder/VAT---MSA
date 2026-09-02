<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `delegations` table -- a taxpayer-to-user
 * "acting on behalf of" grant (Module 4), genuinely distinct from
 * `workflow_delegations` (Module 8's task-reassignment delegation, already
 * ported in Phase 12 slice 5). Verified via a full-repo grep before
 * writing this migration: no command anywhere in the TypeScript source
 * ever writes to this table either (only the demo seed data does); it is
 * read by `getComplianceSnapshot` and, elsewhere, by
 * `platform-repository.ts`'s own delegate-scope resolution. Built purely
 * to let those reads see the same shape the source does, not because a
 * CreateDelegation command exists here to port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->foreignUuid('delegator_user_id')->constrained('users');
            $table->foreignUuid('delegate_user_id')->constrained('users');
            $table->text('scopes');
            $table->string('status', 20);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};

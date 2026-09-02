<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_requests` table -- Phase 12 slice 4
 * (the rest of Access governance): `requestRoleAccess`'s own write target,
 * a maker-checker request for one existing organisation-defined custom
 * role, decided by `decideAccessRequest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('subject_user_id')->constrained('users');
            $table->foreignUuid('organisation_role_id')->constrained('organisation_roles');
            $table->text('justification');
            $table->string('status', 20);
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};

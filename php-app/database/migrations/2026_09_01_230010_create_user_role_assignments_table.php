<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `user_role_assignments` table. No command in
 * this slice writes a row here -- assigning an organisation-defined role
 * to a user is `decideAccessRequest`'s job (Access governance, still
 * deferred) -- but the table itself must exist: `terminateEmployee`'s own
 * offboarding cleanup unconditionally revokes any active row here for the
 * terminated user, and would fail outright against a missing table even
 * though no row can exist yet in this slice's own reachable state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees');
            $table->foreignUuid('organisation_role_id')->constrained('organisation_roles');
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->foreignUuid('assigned_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
    }
};

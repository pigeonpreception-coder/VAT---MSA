<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `organisation_administrators` table.
 * `appointed_by` has no FK in the source (a plain TEXT column, matching
 * `license_events.authority`'s own precedent elsewhere in this
 * migration). `effective_from` is given an explicit
 * `DEFAULT CURRENT_TIMESTAMP` up front (`->useCurrent()`) -- proactively
 * avoiding the exact MariaDB implicit-auto-update bug this migration's own
 * `organisation_licenses.effective_from` fix already found and documented
 * (see that table's own widen migration): this table's own
 * `appointAdministrator` command runs a generic `UPDATE
 * organisation_administrators SET is_primary=0 WHERE ...` across
 * potentially many rows whenever a new primary is appointed, which would
 * silently corrupt every matched row's `effective_from` too if this
 * column were left as the one "exempt" NOT NULL timestamp without an
 * explicit default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_administrators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees');
            $table->string('administrator_role_code', 40);
            $table->foreign('administrator_role_code')->references('code')->on('organisation_administrator_roles');
            $table->text('scope');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->uuid('appointed_by');
            $table->text('approval_reference');

            $table->unique(['organisation_id', 'user_id', 'administrator_role_code'], 'org_admins_org_user_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_administrators');
    }
};

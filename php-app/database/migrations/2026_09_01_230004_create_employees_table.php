<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `employees` table. `position_id` references
 * `positions`, a table this port has not built -- confirmed, by grepping
 * every .ts file under lib/, that `inviteEmployee` (the only writer of
 * this table) always inserts NULL for `position_id` and never reads it
 * back anywhere else either, so the column is kept (schema fidelity) but
 * with no FK to a table that doesn't exist yet. `manager_employee_id` has
 * no FK in the source either (a plain TEXT column, not self-referencing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('employee_number', 40);
            $table->string('full_name');
            $table->string('email');
            $table->uuid('position_id')->nullable();
            $table->foreignUuid('job_title_id')->nullable()->constrained('job_titles');
            $table->foreignUuid('department_id')->nullable()->constrained('departments');
            $table->foreignUuid('business_unit_id')->nullable()->constrained('business_units');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->uuid('manager_employee_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'employee_number']);
            $table->unique(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

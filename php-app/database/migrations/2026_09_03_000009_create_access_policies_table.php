<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_policies` table -- ABAC-style named
 * policies (proposeChange/applyChange's own target type), genuinely
 * distinct from `App\Support\Access\Permissions`' static RBAC (which
 * already fully governs every route in this migration) and from the
 * unrelated, dead `navigation_permissions` table. No command references
 * this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('policy_type', 40);
            $table->text('description');
            $table->text('parameters');
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_policies');
    }
};

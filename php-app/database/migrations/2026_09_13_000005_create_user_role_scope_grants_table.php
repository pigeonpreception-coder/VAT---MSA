<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table for the Super Admin "grant a user an access right" feature
 * (user's own explicit request) -- genuinely distinct from the existing
 * `user_role_assignments` table (Phase 8's tenant-scoped custom-role
 * system: one organisation's own `organisation_roles`, assigned within
 * that one organisation). This table instead records an assignment of one
 * of the app's static roles (`App\Support\Access\Permissions::
 * ROLE_PERMISSIONS`, backed by the `access_roles` registry) to a user, at
 * one of four scope levels -- Local Office / Regional-Provincial /
 * National / Global.
 *
 * `scope_label` (user's own explicit correction of this migration's first
 * revision, which instead referenced a real `tax_authority_units` row --
 * deliberately reverted) is a plain free-text name for Local Office/
 * Regional grants -- e.g. "Windhoek" or "Khomas Region" -- with no
 * hierarchy or FK enforced between office and region labels; National and
 * Global grants leave it null. Which label a granter types is governance
 * context only, same as `scope_level` itself.
 *
 * Granting a row here also sets the target user's own `users.role` column
 * to the granted role_code -- that column is this app's single, actually-
 * enforced source of what a user can do (see Permissions::ROLE_PERMISSIONS/
 * NATIONAL_SCOPE_ROLES), so a grant is not just a record, it takes real
 * effect immediately. `scope_level`/`scope_label` are the grant's own
 * governance context (who authorised what, at what office/region, for
 * audit) -- there is no separate office/region-scoped permission
 * *enforcement* mechanism anywhere else in this codebase to hook into
 * beyond the existing national-vs-tenant split (`TenantScope::isNational`),
 * and this migration does not invent one; see App\Services\Access\
 * UserRoleScopeGrantService's own doc comment for the honest statement
 * of what is and is not enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_scope_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role_code', 40);
            $table->foreign('role_code')->references('code')->on('access_roles');
            $table->enum('scope_level', ['LOCAL_OFFICE', 'REGIONAL', 'NATIONAL', 'GLOBAL']);
            $table->string('scope_label', 120)->nullable();
            $table->enum('status', ['ACTIVE', 'REVOKED'])->default('ACTIVE');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestamp('granted_at')->useCurrent();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_scope_grants');
    }
};

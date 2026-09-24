<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant SaaS pivot phase 6 (2026-09-24): the per-tenant role-
 * catalogue strategy every prior phase's own MIGRATION_MATRIX.md entry has
 * deferred to this phase. `App\Support\Access\Permissions::ROLE_PERMISSIONS`
 * is a single, code-defined map shared by every tax authority today --
 * two organisations under two different authorities minting a
 * 'TAXPAYER_ADMIN' user get identical permissions, with no hook to make
 * that authority-dependent (see this phase's own research: no table
 * anywhere joins tax_authority_id to a row that overrides what a built-in
 * role code grants).
 *
 * This table is that hook: a row is one (tax_authority_id, role_code)
 * pair's *complete* replacement permission set (one row per granted
 * permission, not a diff/patch against the static map -- simplest
 * semantics for a "this authority's own catalogue for this role" concept,
 * and the one `App\Support\Access\AuthorityRolePermissions` implements).
 * Presence of at least one row for a (tax_authority_id, role_code) pair is
 * what "this authority customizes this role" means; absence falls back to
 * `Permissions::effectiveForRole()` exactly as today. Since no rows are
 * seeded for NamRA (`tax-authority-na-namra`) by this phase, every
 * existing tenant sees zero behaviour change by construction -- the same
 * discipline every phase 2-5 change in this pivot already applied.
 *
 * `role_code`/`permission_code` reference the existing `access_roles`/
 * `access_permissions` descriptive-catalogue tables (System A in this
 * phase's own research) purely for referential integrity against a known
 * vocabulary -- the same tables `OrganisationAdminValidator` already
 * validates tenant-defined custom roles against.
 *
 * Deliberately administratively configured (seeded/DB-authored), not
 * self-service: unlike `organisation_role_permissions` (tenant-defined
 * custom roles, capped by `Permissions::tenantGrantablePermissions()`
 * because an organisation admin is a less-trusted actor), a tax
 * authority's own role catalogue is platform-level configuration --
 * the same trust level as `TaxRuleSet`/`VatRuleSet` rows -- so no such
 * ceiling is enforced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tax_authority_id');
            $table->string('role_code', 40);
            $table->string('permission_code', 60);
            $table->timestamps();

            $table->foreign('tax_authority_id')->references('id')->on('tax_authorities')->cascadeOnDelete();
            $table->foreign('role_code')->references('code')->on('access_roles')->cascadeOnDelete();
            $table->foreign('permission_code')->references('code')->on('access_permissions')->cascadeOnDelete();

            $table->unique(['tax_authority_id', 'role_code', 'permission_code'], 'tax_authority_role_permissions_unique');
            $table->index(['tax_authority_id', 'role_code'], 'tax_authority_role_permissions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_role_permissions');
    }
};

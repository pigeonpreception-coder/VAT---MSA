<?php

namespace App\Support\Access;

use Illuminate\Support\Facades\DB;

/**
 * Multi-tenant SaaS pivot phase 6 (2026-09-24): the per-tenant role-
 * catalogue strategy every prior phase's own MIGRATION_MATRIX.md entry
 * has deferred to this phase. `Permissions::ROLE_PERMISSIONS` is a single,
 * code-defined map every tax authority shares today; this class is the
 * one place `User::hasAppPermission()` now goes through instead of calling
 * `Permissions::roleHas()` directly, so a tax authority can optionally
 * define its own complete permission set for a built-in role code via the
 * `tax_authority_role_permissions` table (see that migration's own doc
 * comment for the full design and why it is a full replacement set, not a
 * diff against the static map).
 *
 * A null `$taxAuthorityId` (a national-scope actor -- see
 * `User::taxAuthorityId()`'s own doc comment, they have no `organisation()`
 * to resolve an authority from) or an authority with no override rows for
 * that role both resolve to exactly `Permissions::effectiveForRole()` --
 * today's only behaviour, preserved by construction since no rows are
 * seeded for NamRA (`tax-authority-na-namra`) by this phase.
 */
class AuthorityRolePermissions
{
    public static function hasCatalogue(?string $taxAuthorityId, string $role): bool
    {
        if ($taxAuthorityId === null) {
            return false;
        }

        return DB::table('tax_authority_role_permissions')
            ->where('tax_authority_id', $taxAuthorityId)
            ->where('role_code', $role)
            ->exists();
    }

    /** @return list<string> */
    public static function forRole(?string $taxAuthorityId, string $role): array
    {
        if ($taxAuthorityId === null) {
            return Permissions::effectiveForRole($role);
        }

        $override = DB::table('tax_authority_role_permissions')
            ->where('tax_authority_id', $taxAuthorityId)
            ->where('role_code', $role)
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->all();

        return $override !== [] ? $override : Permissions::effectiveForRole($role);
    }

    public static function roleHas(?string $taxAuthorityId, string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($taxAuthorityId, $role), true);
    }
}

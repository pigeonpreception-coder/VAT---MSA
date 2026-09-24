<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ported from lib/domain/access.ts's requireTaxpayerScope/isNationalScope --
 * Section 3 of SECURITY_GAP_ASSESSMENT.md ("Multi-tenant isolation") found
 * no missing tenant-scope check across the original's entire data layer;
 * this is the equivalent guard every migrated repository/service method
 * must call before reading or writing a taxpayer-owned record, never
 * trusting a taxpayer_id/organisation_id supplied by a form or URL alone.
 *
 * Renamed from TenantScope (2026-09-24, multi-tenant SaaS pivot phase 1):
 * this class has only ever isolated one *taxpayer's* data from another
 * within a single deployment -- it has nothing to do with isolating one
 * *licensed national platform* (e.g. a second country's NamRA-equivalent)
 * from another, which is what "tenant" now means as this codebase grows a
 * real multi-tenant concept. Keeping the old name would have made every
 * future reference to "tenant" ambiguous. See docs/MIGRATION_MATRIX.md's
 * own entry for this rename.
 */
final class TaxpayerScope
{
    public static function isNational(User $user): bool
    {
        return $user->taxpayer_id === null && in_array($user->role, Permissions::NATIONAL_SCOPE_ROLES, true);
    }

    /**
     * @throws AuthorizationException if the actor is not national-scope and
     *   the record's taxpayer does not match their own.
     */
    public static function requireTaxpayer(User $user, ?string $taxpayerId): void
    {
        if (! self::isNational($user) && $user->taxpayer_id !== $taxpayerId) {
            throw new AuthorizationException('The requested record is outside your authorised taxpayer scope.');
        }
    }
}

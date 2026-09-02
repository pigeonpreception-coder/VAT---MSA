<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/auth.ts's buildUserContext -- the `dynamicPermissions`
 * half of UserContext, which `hasPermission` (lib/domain/access.ts) ORs in
 * on top of the static role grants. This was a known, explicitly-deferred
 * gap since Phase 7 (see the doc comments this class' own callers already
 * carried: Permissions.php's own class doc, and User::hasAppPermission's
 * "resolved separately once organisation-role grants are migrated (Phase
 * 7/8)") -- genuinely unbuildable until Phase 12 slice 2 created
 * `organisation_roles`/`organisation_role_permissions`/
 * `user_role_assignments`. Portal navigation is the first slice whose own
 * correctness actually depends on it (`getEffectiveNavigation`'s row
 * filter calls the *full* `hasPermission`, not just the static half), so
 * it is closed now rather than carried forward a second time.
 *
 * The source recomputes `dynamicPermissions` once per request, in session
 * bootstrap, then treats it as a plain in-memory array for the rest of
 * that request -- an optimisation, not a correctness requirement.
 * Deliberately not replicated here with a static/process-lifetime cache:
 * `artisan test` runs the entire suite in one PHP process, and a stale
 * cache surviving a role assignment/revocation within a single test would
 * be a subtler, harder-to-trust bug than the extra query cost of
 * re-running this join on every call. Re-querying every time is the
 * correct default until a genuine per-request cache exists.
 */
class DynamicPermissions
{
    /**
     * The source resolves `actor.organisationId` from the user's first
     * ACTIVE `organisation_memberships` row (`ORDER BY created_at LIMIT 1`)
     * at session-bootstrap time -- reproduced verbatim here rather than
     * this migration's usual taxpayer_id-based organisation resolution
     * (see `LicenseResolver::resolveOrganisation`'s own doc comment for
     * why that simplification exists elsewhere), since dynamic permissions
     * are specifically a *membership*-scoped grant in the source, not a
     * taxpayer-scoped one.
     */
    public static function homeOrganisationId(User $user): ?string
    {
        return DB::table('organisation_memberships')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->orderBy('created_at')
            ->value('organisation_id');
    }

    /** @return list<string> */
    public static function forUser(User $user): array
    {
        $organisationId = self::homeOrganisationId($user);
        if (! $organisationId) {
            return [];
        }

        return DB::table('user_role_assignments as ura')
            ->join('organisation_roles as r', function ($join) {
                $join->on('r.id', '=', 'ura.organisation_role_id')->where('r.status', 'ACTIVE');
            })
            ->join('organisation_role_permissions as rp', 'rp.organisation_role_id', '=', 'r.id')
            ->where('ura.user_id', $user->id)
            ->where('ura.organisation_id', $organisationId)
            ->where('ura.status', 'ACTIVE')
            ->distinct()
            ->pluck('rp.permission_code')
            ->all();
    }
}

<?php

namespace App\Services\Access;

use App\Exceptions\AccessRightsValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AccessRole;
use App\Models\User;
use App\Models\UserRoleScopeGrant;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Backs the Super Admin "grant a user an access right" feature (user's
 * own explicit request): assign one of the app's static roles
 * (App\Models\AccessRole, backed by App\Support\Access\Permissions::
 * ROLE_PERMISSIONS) to a user, at one of four scope levels -- Local
 * Office, Regional/Provincial, National or Global.
 *
 * `scope_label` (user's own explicit correction of this class's first
 * revision, which instead required referencing a real `tax_authority_units`
 * office/region row in a parent-child hierarchy -- deliberately reverted)
 * is a plain free-text name for Local Office/Regional grants, e.g.
 * "Windhoek" or "Khomas Region" -- no hierarchy or FK is enforced between
 * office and region labels; National/Global grants leave it null.
 *
 * Honest statement of what this actually enforces, since "scope level" is
 * new territory this migration has not modelled before now: granting a
 * row here immediately sets the target user's own `users.role` column to
 * the granted role -- that column is this app's one real, everywhere-
 * enforced source of what a user can do (every `$user->hasAppPermission()`
 * check, every `PortalDefinitions` role list, `TenantScope::isNational`),
 * so the grant takes real effect, not just a record. `scope_level` and
 * `scope_label` are the grant's own governance context for auditing who
 * authorised what at which office/region -- there is no separate office/
 * region-scoped *enforcement* mechanism anywhere else in this codebase
 * (the only existing scope split is national-vs-tenant,
 * `TenantScope::isNational`), and this class does not invent one. A user
 * granted a NamRA-facing role at LOCAL_OFFICE scope gets that role's full,
 * ordinary permission set exactly as if granted at NATIONAL/GLOBAL scope
 * -- the office/region label is recorded, not yet a live data filter.
 *
 * RT-009 (2026-09-13 red-team pass, High): `grant()` originally had no
 * idempotency protection at all -- a live reproduction fired two
 * concurrent identical grant requests against the real `/access-rights`
 * screen (post step-up) and confirmed two distinct `ACTIVE`
 * `user_role_scope_grants` rows were created for one admin action. Both
 * rows target the same user/role, so `users.role` itself ends up correct
 * either way, but the grants audit trail -- this feature's own most
 * sensitive record, per this class's own doc comment -- is corrupted: an
 * admin who later revokes "the" grant sees exactly one row flip to
 * REVOKED while a second, forgotten ACTIVE grant for the same role
 * silently remains, making "does this user still hold an active grant"
 * unreliable to answer from this table alone. Fixed with this codebase's
 * own established `CommandLedger` idempotency pattern (`docs/
 * MIGRATION_MATRIX.md`'s "Duplicate-submission hardening" section).
 *
 * Defense-in-depth (2026-09-14 authorization-isolation audit): `grant()`
 * can assign any role, including `SUPER_ADMIN` itself, and `revoke()`
 * accepts any `UserRoleScopeGrant` by id with no ownership check of its
 * own -- both methods relied entirely on `access-rights:manage` being
 * held only by national-scope roles (`Permissions::ROLE_PERMISSIONS`),
 * with no query- or service-level check confirming that here. Not
 * exploitable today (verified: only `NAMRA_SYSTEM_ADMIN`/`SUPER_ADMIN`
 * hold the permission, both in `Permissions::NATIONAL_SCOPE_ROLES`), but
 * a single future permission-map edit granting `access-rights:manage` to
 * a tenant-scoped role would silently reopen this screen to that
 * tenant -- for a screen that can grant `SUPER_ADMIN` itself, that is
 * too large a blast radius to leave resting on the permission map alone.
 * Both methods now assert `TenantScope::isNational()` directly.
 */
class UserRoleScopeGrantService
{
    /** @var list<string> */
    public const SCOPE_LEVELS = ['LOCAL_OFFICE', 'REGIONAL', 'NATIONAL', 'GLOBAL'];

    /** Scope levels whose grant requires a non-empty free-text `scope_label`. */
    private const LABELLED_SCOPES = ['LOCAL_OFFICE', 'REGIONAL'];

    /** @param array{user_id?: mixed, role_code?: mixed, scope_level?: mixed, scope_label?: mixed} $payload */
    public function grant(array $payload, User $grantedBy, string $idempotencyKey): UserRoleScopeGrant
    {
        if (! TenantScope::isNational($grantedBy)) {
            throw new AuthorizationException('Access rights may only be granted by a national-scope administrator.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $errors = [];

        $userId = (string) ($payload['user_id'] ?? '');
        $targetUser = $userId !== '' ? User::find($userId) : null;
        if (! $targetUser) {
            $errors[] = ['code' => 'NOT_FOUND', 'path' => 'user_id', 'message' => 'Select a real user.'];
        } elseif ($targetUser->id === $grantedBy->id) {
            // Matches this codebase's own consistent self-approval/SoD
            // convention elsewhere (VAT rules, workflows, refund claims,
            // authority governance onboarding decisions) -- granting your
            // own account a role through this same screen is the same
            // class of privileged self-service those all already refuse.
            $errors[] = ['code' => 'SELF_GRANT', 'path' => 'user_id', 'message' => 'You cannot grant yourself an access right.'];
        }

        $roleCode = mb_strtoupper((string) ($payload['role_code'] ?? ''));
        $role = $roleCode !== '' ? AccessRole::where('code', $roleCode)->where('status', 'ACTIVE')->first() : null;
        if (! $role) {
            $errors[] = ['code' => 'INVALID', 'path' => 'role_code', 'message' => 'Select a real, active role.'];
        }

        $scopeLevel = mb_strtoupper((string) ($payload['scope_level'] ?? ''));
        if (! in_array($scopeLevel, self::SCOPE_LEVELS, true)) {
            $errors[] = ['code' => 'INVALID', 'path' => 'scope_level', 'message' => 'Select a real scope level.'];
        }

        $scopeLabel = $payload['scope_label'] ?? null;
        $scopeLabel = is_string($scopeLabel) ? trim($scopeLabel) : '';
        $scopeLabel = $scopeLabel !== '' ? $scopeLabel : null;

        if (in_array($scopeLevel, self::LABELLED_SCOPES, true)) {
            if (! $scopeLabel) {
                $errors[] = ['code' => 'REQUIRED', 'path' => 'scope_label', 'message' => 'Name the office/region for this scope level.'];
            }
        } else {
            // NATIONAL/GLOBAL grants never carry an office/region label,
            // regardless of what the form happened to submit.
            $scopeLabel = null;
        }

        if ($errors !== []) {
            throw new AccessRightsValidationException($errors);
        }

        $requestHash = CommandLedger::requestHash([
            'user_id' => $targetUser->id, 'role_code' => $role->code, 'scope_level' => $scopeLevel, 'scope_label' => $scopeLabel,
        ]);
        $prior = CommandLedger::prior($grantedBy->id, 'GRANT_ACCESS_RIGHT', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return UserRoleScopeGrant::findOrFail($prior);
        }

        return DB::transaction(function () use ($targetUser, $role, $scopeLevel, $scopeLabel, $grantedBy, $idempotencyKey, $requestHash) {
            $grant = UserRoleScopeGrant::create([
                'user_id' => $targetUser->id,
                'role_code' => $role->code,
                'scope_level' => $scopeLevel,
                'scope_label' => $scopeLabel,
                'status' => 'ACTIVE',
                'granted_by' => $grantedBy->id,
                'granted_at' => now(),
            ]);

            $targetUser->update(['role' => $role->code]);
            CommandLedger::record($grantedBy->id, 'GRANT_ACCESS_RIGHT', $idempotencyKey, $requestHash, 'USER_ROLE_SCOPE_GRANT', $grant->id, now());

            return $grant;
        });
    }

    public function revoke(UserRoleScopeGrant $grant, User $revokedBy): void
    {
        if (! TenantScope::isNational($revokedBy)) {
            throw new AuthorizationException('Access rights may only be revoked by a national-scope administrator.');
        }
        if ($grant->status !== 'ACTIVE') {
            throw new RepositoryConflictException('This access grant has already been revoked.');
        }

        $grant->update(['status' => 'REVOKED', 'revoked_by' => $revokedBy->id, 'revoked_at' => now()]);
    }
}

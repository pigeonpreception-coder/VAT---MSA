<?php

namespace App\Support\Access;

/**
 * Direct, line-for-line PHP port of lib/domain/access.ts from the original
 * VAT-MSA TypeScript source. This is the single source of truth for RBAC in
 * the migrated system -- every permission string and every role's grant set
 * is copied verbatim, not reinterpreted, so behaviour matches exactly.
 *
 * Static, code-defined role -> permission maps (not database-backed) on
 * purpose, matching the source: the 22 built-in roles' permissions are a
 * fixed part of the application, not admin-editable data. Tenant-defined
 * custom roles (organisation_role_permissions, Phase 8) layer additional,
 * dynamically-granted permissions on top via User::dynamicPermissions,
 * exactly as the source's UserContext.dynamicPermissions did.
 *
 * `authority-governance:read`/`authority-governance:manage` (NAMRA_SYSTEM_SUPPORT
 * and NAMRA_SYSTEM_ADMIN only) are the one exception to "from access.ts":
 * the source never grants either permission through lib/domain/access.ts's
 * own static ROLE_PERMISSIONS map at all -- it grants them exclusively
 * through a separate, genuinely dynamic `role_permission_grants` database
 * table (db/runtime.ts's own seed: rpg-pa-agr/rpg-pa-agm/rpg-nsa-agr/
 * rpg-nsa-agm), which this migration's `role_permission_grants` table
 * (migrated schema-only in Phase 4) has never had a runtime reader for --
 * `User::hasAppPermission` only consults this static map and the
 * tenant-scoped `DynamicPermissions`. Every other permission that table
 * seeds already has a direct, line-for-line static equivalent here; these
 * two are the only ones that don't, added for the Authority Governance
 * module (NamRA Administration portal backend) with the exact two-role
 * grant set the source's own seed data produces -- a targeted
 * transcription of that table's effective result, not a new permission
 * mechanism.
 */
final class Permissions
{
    /** @var array<string, list<string>> */
    public const ROLE_PERMISSIONS = [
        // NamRA System Support (formerly PILOT_ADMIN, then NAMRA_STAFF, both
        // renamed at the user's own explicit request): retains every other
        // national operations permission it always had, and now holds
        // authority-governance:read (needed for /portal/namra-admin) and
        // developer:read/manage (needed for /portal/developer and its raw
        // JSON mirror, PlatformSnapshotController::developerPortal) --
        // both permissions those portals' own gates check directly, not
        // just role-list membership. This role's scope stays National
        // (Permissions::NATIONAL_SCOPE_ROLES is unchanged for it) even
        // with the Developer grant, unlike SUPER_ADMIN/INFRASTRUCTURE_
        // ADMIN/SECURITY_ANALYST, which are all Global scope -- the
        // user's own explicit distinction. platform:read is kept -- it
        // also gates the separate Platform Config feature
        // (PlatformConfigController/ViewController), not just the Super
        // Administration portal; PortalDefinitions' own 'super-admin'
        // role list (not this permission) is what actually blocks this
        // role from that specific portal, via SuperAdminPortalController's
        // own getAvailablePortals() re-check.
        'NAMRA_SYSTEM_SUPPORT' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'taxpayers:suspend', 'registrations:read', 'registrations:submit',
            'registrations:approve', 'organisations:manage', 'invoices:read', 'invoices:submit', 'invoices:cancel', 'exceptions:read',
            'returns:read', 'returns:generate', 'returns:approve', 'returns:submit', 'vat-adjustments:manage', 'vat-rules:read',
            'vat-rules:manage', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'cases:override-sod', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'refunds:request', 'refunds:review', 'risk:read', 'risk:review',
            'communications:manage', 'notifications:manage', 'consents:manage', 'integrations:read', 'integrations:manage',
            'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'reports:executive',
            'platform:read', 'payments:read', 'payments:record', 'audit:read', 'security:read', 'security:manage', 'commercial:read',
            'parties:manage', 'quotations:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read',
            'expenses:manage', 'inventory:read', 'inventory:manage', 'projects:read', 'projects:manage', 'imports:read',
            'imports:manage', 'documents:read', 'documents:upload', 'documents:manage',
            'authority-governance:read', 'developer:read', 'developer:manage',
            'fixed-assets:read', 'fixed-assets:manage', 'logistics:read', 'logistics:manage',
        ],
        // TAXPAYER_OWNER: no longer includes developer:read/manage -- this
        // role must not reach the Developer portal or its raw JSON mirror
        // (PlatformSnapshotController::developerPortal, which checks
        // developer:read directly with no portal-role-list re-check).
        'TAXPAYER_OWNER' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'registrations:submit', 'organisations:manage',
            'invoices:read', 'invoices:submit', 'exceptions:read', 'returns:read', 'returns:generate', 'returns:approve',
            'returns:submit', 'vat-adjustments:manage', 'compliance:read', 'communications:respond', 'disputes:manage',
            'refunds:read', 'refunds:request', 'consents:manage', 'integrations:read', 'integrations:manage',
            'taxpayer-systems:read', 'taxpayer-systems:manage',
            'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'commercial:read', 'parties:manage',
            'quotations:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read', 'expenses:manage',
            'inventory:read', 'inventory:manage', 'projects:read', 'projects:manage', 'imports:read', 'imports:manage',
            'documents:read', 'documents:upload', 'fixed-assets:read', 'fixed-assets:manage', 'logistics:read', 'logistics:manage',
        ],
        'TAXPAYER_ADMIN' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'organisations:manage', 'invoices:read',
            'invoices:submit', 'exceptions:read', 'returns:read', 'returns:generate', 'returns:approve', 'returns:submit',
            'vat-adjustments:manage', 'compliance:read', 'communications:respond', 'disputes:manage', 'refunds:read',
            'refunds:request', 'consents:manage', 'integrations:read', 'integrations:manage', 'developer:read', 'developer:manage',
            'taxpayer-systems:read', 'taxpayer-systems:manage',
            'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'commercial:read', 'parties:manage', 'quotations:manage',
            'accounting:read', 'expenses:read', 'expenses:manage', 'inventory:read', 'inventory:manage', 'projects:read',
            'projects:manage', 'imports:read', 'imports:manage', 'documents:read', 'documents:upload',
            'fixed-assets:read', 'fixed-assets:manage', 'logistics:read', 'logistics:manage',
        ],
        'TAXPAYER_ACCOUNTANT' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'invoices:read', 'invoices:submit', 'exceptions:read',
            'returns:read', 'returns:generate', 'returns:submit', 'vat-adjustments:manage', 'communications:respond',
            'commercial:read', 'parties:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read',
            'expenses:manage', 'projects:read', 'imports:read', 'imports:manage', 'documents:read', 'documents:upload',
            'fixed-assets:read',
        ],
        'TAXPAYER_STAFF' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'invoices:submit', 'exceptions:read', 'commercial:read',
            'parties:manage', 'quotations:manage', 'expenses:read', 'expenses:manage', 'inventory:read', 'inventory:manage',
            'projects:read', 'documents:read', 'documents:upload', 'fixed-assets:read', 'fixed-assets:manage',
            'logistics:read', 'logistics:manage',
        ],
        'TAXPAYER_VIEWER' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'returns:read', 'commercial:read', 'accounting:read',
            'expenses:read', 'inventory:read', 'projects:read', 'imports:read', 'documents:read',
            'fixed-assets:read', 'logistics:read',
        ],
        'SELLER_ADMIN' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'invoices:submit', 'exceptions:read', 'returns:read',
            'commercial:read', 'parties:manage', 'quotations:manage', 'inventory:read', 'inventory:manage', 'projects:read',
            'projects:manage',
        ],
        'SELLER_OPERATOR' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'invoices:submit', 'exceptions:read', 'commercial:read',
            'parties:manage', 'quotations:manage', 'inventory:read', 'inventory:manage', 'projects:read',
        ],
        'SELLER_VIEWER' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'returns:read', 'commercial:read', 'inventory:read', 'projects:read',
        ],
        'BUYER_ADMIN' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'exceptions:read', 'returns:read', 'parties:manage',
            'expenses:read', 'expenses:manage', 'imports:read', 'imports:manage', 'documents:read', 'documents:upload',
        ],
        'BUYER_USER' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'exceptions:read', 'parties:manage', 'expenses:read',
            'expenses:manage', 'imports:read', 'documents:read', 'documents:upload',
        ],
        'NAMRA_COMPLIANCE_OFFICER' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'invoices:read', 'exceptions:read',
            'returns:read', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'disputes:manage', 'obligations:manage',
            'refunds:read', 'risk:read', 'risk:review', 'communications:manage', 'notifications:manage', 'integrations:read',
            'taxpayer-systems:read',
            'reports:read', 'reports:run', 'platform:read', 'payments:read', 'vat-rules:read',
        ],
        'NAMRA_VAT_AUDITOR' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'invoices:read', 'exceptions:read',
            'returns:read', 'audit:read', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'risk:read', 'risk:review', 'vat-rules:read', 'reports:read', 'reports:run',
        ],
        'NAMRA_VAT_SENIOR_AUDITOR' => [
            'dashboard:read', 'taxpayers:read', 'returns:read', 'compliance:read', 'refunds:read', 'refunds:review', 'risk:read',
            'communications:manage', 'notifications:manage', 'payments:read', 'payments:record',
        ],
        'NAMRA_VAT_SUPERVISOR' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'invoices:read', 'exceptions:read',
            'returns:read', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'cases:override-sod', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'refunds:review', 'risk:read', 'risk:review', 'communications:manage',
            'integrations:read', 'integrations:manage', 'taxpayer-systems:read', 'taxpayer-systems:approve',
            'reports:read', 'reports:run', 'reports:executive', 'platform:read',
            'payments:read', 'payments:record', 'audit:read', 'vat-rules:read',
        ],
        // access-rights:read/manage added at the user's own explicit
        // request: SUPER_ADMIN delegates access-rights allocation to
        // NAMRA_SYSTEM_ADMIN, who then allocates roles/access rights to
        // the rest of NamRA's own system users through the same screen --
        // see App\Services\Access\UserRoleScopeGrantService's own doc
        // comment. The gate is a flat permission check like every other
        // role here, not a target-role-restricted delegation: this role
        // reaches the same "grant a user an access right" screen SUPER_ADMIN
        // does, same as SUPER_ADMIN's own grant below.
        'NAMRA_SYSTEM_ADMIN' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'taxpayers:suspend', 'registrations:read', 'registrations:approve',
            'organisations:manage', 'administration:read', 'administration:manage', 'vat-rules:read', 'vat-rules:manage',
            'invoices:cancel', 'documents:manage', 'authority-governance:read', 'authority-governance:manage',
            'access-rights:read', 'access-rights:manage',
        ],
        // SUPER_ADMIN: global/national scope (see NATIONAL_SCOPE_ROLES
        // below), with access to all six portals (user's own explicit
        // request) -- developer:read/manage for Developer, authority-
        // governance:read for NamRA Administration (whose own permission
        // gate checks it directly, not just role-list membership);
        // dashboard:read (already held) covers Buyer/Seller/NamRA,
        // platform:read (already held) covers Super Administration.
        // access-rights:read/manage (user's own explicit request) gate the
        // new "grant a user an access right" screen -- see
        // App\Services\Access\UserRoleScopeGrantService's own doc comment.
        'SUPER_ADMIN' => [
            'dashboard:read', 'platform:read', 'platform:manage', 'integrations:read', 'integrations:manage', 'security:read',
            'security:manage', 'developer:read', 'developer:manage', 'authority-governance:read',
            'access-rights:read', 'access-rights:manage',
        ],
        // INFRASTRUCTURE_ADMIN: global scope (see NATIONAL_SCOPE_ROLES
        // below) at the user's own explicit request, now reaching all six
        // portals -- authority-governance:read (NamRA Administration) and
        // developer:read/manage (Developer, plus its raw JSON mirror,
        // PlatformSnapshotController::developerPortal) added; dashboard:read
        // (already held) covers Buyer/Seller/NamRA, platform:read (already
        // held) covers Super Administration.
        'INFRASTRUCTURE_ADMIN' => [
            'dashboard:read', 'platform:read', 'platform:manage', 'integrations:read', 'security:read', 'security:manage',
            'authority-governance:read', 'developer:read', 'developer:manage',
        ],
        'DEVELOPER_PARTNER' => ['dashboard:read', 'developer:read', 'developer:manage', 'integrations:read'],
        'INTERNAL_AUDITOR' => ['dashboard:read', 'audit:read'],
        // SECURITY_ANALYST: global scope (added to NATIONAL_SCOPE_ROLES
        // below) at the user's own explicit request, now reaching all six
        // portals -- previously it was listed on the Super Administration
        // portal's own role list without ever holding platform:read (the
        // exact "listed but denied" gap this file's own history flagged
        // as intentional); the user's own explicit request closes that
        // gap for real. dashboard:read (already held) covers Buyer/Seller/
        // NamRA; platform:read added for Super Administration; authority-
        // governance:read for NamRA Administration; developer:read/manage
        // for Developer and its raw JSON mirror.
        'SECURITY_ANALYST' => [
            'dashboard:read', 'security:read', 'audit:read', 'security:manage',
            'platform:read', 'authority-governance:read', 'developer:read', 'developer:manage',
        ],
    ];

    private const WORKSPACE_READ = ['workspace:read', 'search:read', 'licensing:read'];

    /** @var list<string> */
    private const ORGANISATION_CONTROL = [
        'workspace:read', 'search:read', 'licensing:read',
        'licensing:request', 'licensing:manage',
        'administration:read', 'administration:manage',
        'employees:read', 'employees:manage',
        'roles:read', 'roles:manage',
        'workflows:read', 'workflows:manage', 'workflows:decide',
        'access-governance:read', 'access-governance:manage',
    ];

    /** @var array<string, list<string>> */
    public const CONTROL_PLANE_PERMISSIONS = [
        'NAMRA_SYSTEM_SUPPORT' => self::ORGANISATION_CONTROL,
        'TAXPAYER_OWNER' => self::ORGANISATION_CONTROL,
        'TAXPAYER_ADMIN' => self::ORGANISATION_CONTROL,
        'TAXPAYER_ACCOUNTANT' => [...self::WORKSPACE_READ, 'employees:read', 'roles:read', 'workflows:read', 'workflows:decide', 'access-governance:read'],
        'TAXPAYER_STAFF' => ['workspace:read', 'search:read'],
        'TAXPAYER_VIEWER' => ['workspace:read', 'search:read'],
        'NAMRA_SYSTEM_ADMIN' => self::ORGANISATION_CONTROL,
    ];

    /** @var list<string> */
    public const NATIONAL_SCOPE_ROLES = [
        'NAMRA_SYSTEM_SUPPORT', 'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_VAT_AUDITOR', 'NAMRA_VAT_SENIOR_AUDITOR',
        'NAMRA_VAT_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST', 'SUPER_ADMIN',
        'INFRASTRUCTURE_ADMIN',
    ];

    /** Roles that never represent a tenant/organisation -- national tax-administration roles plus platform-technical roles. */
    private const NATIONAL_OR_PLATFORM_ONLY_ROLES = [
        'NAMRA_SYSTEM_SUPPORT', 'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_VAT_AUDITOR', 'NAMRA_VAT_SENIOR_AUDITOR',
        'NAMRA_VAT_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
        'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN',
    ];

    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true)
            || in_array($permission, self::CONTROL_PLANE_PERMISSIONS[$role] ?? [], true);
    }

    /** @return list<string> */
    public static function effectiveForRole(string $role): array
    {
        $combined = array_unique([
            ...(self::ROLE_PERMISSIONS[$role] ?? []),
            ...(self::CONTROL_PLANE_PERMISSIONS[$role] ?? []),
        ]);
        sort($combined);
        return array_values($combined);
    }

    /**
     * SECURITY_GAP_ASSESSMENT.md item #5's fix, ported verbatim: the real
     * safe ceiling for what a tenant-*defined* custom role may ever be
     * granted -- the union of every permission any tenant/organisation-
     * facing built-in role legitimately holds, excluding national/platform
     * roles. A tenant-defined role can never be granted more than this.
     *
     * @return list<string>
     */
    public static function tenantGrantablePermissions(): array
    {
        $union = [];
        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            if (in_array($role, self::NATIONAL_OR_PLATFORM_ONLY_ROLES, true)) {
                continue;
            }
            $union = [...$union, ...$permissions];
        }
        foreach (self::CONTROL_PLANE_PERMISSIONS as $role => $permissions) {
            if (in_array($role, self::NATIONAL_OR_PLATFORM_ONLY_ROLES, true)) {
                continue;
            }
            $union = [...$union, ...$permissions];
        }
        $union = array_values(array_unique($union));
        sort($union);
        return $union;
    }
}

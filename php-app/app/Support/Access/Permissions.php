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
 * `authority-governance:read`/`authority-governance:manage` (PILOT_ADMIN
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
        'PILOT_ADMIN' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'taxpayers:suspend', 'registrations:read', 'registrations:submit',
            'registrations:approve', 'organisations:manage', 'invoices:read', 'invoices:submit', 'invoices:cancel', 'exceptions:read',
            'returns:read', 'returns:generate', 'returns:approve', 'returns:submit', 'vat-adjustments:manage', 'vat-rules:read',
            'vat-rules:manage', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'cases:override-sod', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'refunds:request', 'refunds:review', 'risk:read', 'risk:review',
            'communications:manage', 'notifications:manage', 'consents:manage', 'integrations:read', 'integrations:manage',
            'developer:read', 'developer:manage', 'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'reports:executive',
            'platform:read', 'payments:read', 'payments:record', 'audit:read', 'security:read', 'security:manage', 'commercial:read',
            'parties:manage', 'quotations:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read',
            'expenses:manage', 'inventory:read', 'inventory:manage', 'projects:read', 'projects:manage', 'imports:read',
            'imports:manage', 'documents:read', 'documents:upload', 'documents:manage',
            'authority-governance:read', 'authority-governance:manage',
        ],
        'TAXPAYER_OWNER' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'registrations:submit', 'organisations:manage',
            'invoices:read', 'invoices:submit', 'exceptions:read', 'returns:read', 'returns:generate', 'returns:approve',
            'returns:submit', 'vat-adjustments:manage', 'compliance:read', 'communications:respond', 'disputes:manage',
            'refunds:read', 'refunds:request', 'consents:manage', 'integrations:read', 'integrations:manage', 'developer:read',
            'developer:manage', 'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'commercial:read', 'parties:manage',
            'quotations:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read', 'expenses:manage',
            'inventory:read', 'inventory:manage', 'projects:read', 'projects:manage', 'imports:read', 'imports:manage',
            'documents:read', 'documents:upload',
        ],
        'TAXPAYER_ADMIN' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'organisations:manage', 'invoices:read',
            'invoices:submit', 'exceptions:read', 'returns:read', 'returns:generate', 'returns:approve', 'returns:submit',
            'vat-adjustments:manage', 'compliance:read', 'communications:respond', 'disputes:manage', 'refunds:read',
            'refunds:request', 'consents:manage', 'integrations:read', 'integrations:manage', 'developer:read', 'developer:manage',
            'offline:read', 'offline:sync', 'reports:read', 'reports:run', 'commercial:read', 'parties:manage', 'quotations:manage',
            'accounting:read', 'expenses:read', 'expenses:manage', 'inventory:read', 'inventory:manage', 'projects:read',
            'projects:manage', 'imports:read', 'imports:manage', 'documents:read', 'documents:upload',
        ],
        'TAXPAYER_ACCOUNTANT' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'invoices:read', 'invoices:submit', 'exceptions:read',
            'returns:read', 'returns:generate', 'returns:submit', 'vat-adjustments:manage', 'communications:respond',
            'commercial:read', 'parties:manage', 'accounting:read', 'accounting:post', 'accounting:close-period', 'expenses:read',
            'expenses:manage', 'projects:read', 'imports:read', 'imports:manage', 'documents:read', 'documents:upload',
        ],
        'TAXPAYER_STAFF' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'invoices:submit', 'exceptions:read', 'commercial:read',
            'parties:manage', 'quotations:manage', 'expenses:read', 'expenses:manage', 'inventory:read', 'inventory:manage',
            'projects:read', 'documents:read', 'documents:upload',
        ],
        'TAXPAYER_VIEWER' => [
            'dashboard:read', 'identity:read', 'invoices:read', 'returns:read', 'commercial:read', 'accounting:read',
            'expenses:read', 'inventory:read', 'projects:read', 'imports:read', 'documents:read',
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
            'reports:read', 'reports:run', 'platform:read', 'payments:read', 'vat-rules:read',
        ],
        'NAMRA_AUDITOR' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'invoices:read', 'exceptions:read',
            'returns:read', 'audit:read', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'risk:read', 'risk:review', 'vat-rules:read', 'reports:read', 'reports:run',
        ],
        'NAMRA_REFUND_OFFICER' => [
            'dashboard:read', 'taxpayers:read', 'returns:read', 'compliance:read', 'refunds:read', 'refunds:review', 'risk:read',
            'communications:manage', 'notifications:manage', 'payments:read', 'payments:record',
        ],
        'NAMRA_SUPERVISOR' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'registrations:read', 'invoices:read', 'exceptions:read',
            'returns:read', 'reconciliation:manage', 'compliance:read', 'cases:manage', 'cases:override-sod', 'disputes:manage',
            'obligations:manage', 'refunds:read', 'refunds:review', 'risk:read', 'risk:review', 'communications:manage',
            'integrations:read', 'integrations:manage', 'reports:read', 'reports:run', 'reports:executive', 'platform:read',
            'payments:read', 'payments:record', 'audit:read', 'vat-rules:read',
        ],
        'NAMRA_SYSTEM_ADMIN' => [
            'dashboard:read', 'identity:read', 'taxpayers:read', 'taxpayers:suspend', 'registrations:read', 'registrations:approve',
            'organisations:manage', 'administration:read', 'administration:manage', 'vat-rules:read', 'vat-rules:manage',
            'invoices:cancel', 'documents:manage', 'authority-governance:read', 'authority-governance:manage',
        ],
        'SUPER_ADMIN' => [
            'dashboard:read', 'platform:read', 'platform:manage', 'integrations:read', 'integrations:manage', 'security:read',
            'security:manage',
        ],
        'INFRASTRUCTURE_ADMIN' => [
            'dashboard:read', 'platform:read', 'platform:manage', 'integrations:read', 'security:read', 'security:manage',
        ],
        'DEVELOPER_PARTNER' => ['dashboard:read', 'developer:read', 'developer:manage', 'integrations:read'],
        'INTERNAL_AUDITOR' => ['dashboard:read', 'audit:read'],
        'SECURITY_ANALYST' => ['dashboard:read', 'security:read', 'audit:read', 'security:manage'],
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
        'PILOT_ADMIN' => self::ORGANISATION_CONTROL,
        'TAXPAYER_OWNER' => self::ORGANISATION_CONTROL,
        'TAXPAYER_ADMIN' => self::ORGANISATION_CONTROL,
        'TAXPAYER_ACCOUNTANT' => [...self::WORKSPACE_READ, 'employees:read', 'roles:read', 'workflows:read', 'workflows:decide', 'access-governance:read'],
        'TAXPAYER_STAFF' => ['workspace:read', 'search:read'],
        'TAXPAYER_VIEWER' => ['workspace:read', 'search:read'],
        'NAMRA_SYSTEM_ADMIN' => self::ORGANISATION_CONTROL,
    ];

    /** @var list<string> */
    public const NATIONAL_SCOPE_ROLES = [
        'PILOT_ADMIN', 'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER',
        'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
    ];

    /** Roles that never represent a tenant/organisation -- national tax-administration roles plus platform-technical roles. */
    private const NATIONAL_OR_PLATFORM_ONLY_ROLES = [
        'PILOT_ADMIN', 'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER',
        'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
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

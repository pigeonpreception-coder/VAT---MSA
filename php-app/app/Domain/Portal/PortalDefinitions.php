<?php

namespace App\Domain\Portal;

/**
 * Direct port of lib/domain/portals.ts -- Module 1's static, code-defined
 * portal switchboard (6 portals, matching this migration's own verified
 * source-inventory count). Found while closing out `control-plane-
 * repository.ts` entirely: `lib/portals.ts`'s `getAvailablePortals` is a
 * genuinely separate file/function -- not part of control-plane-
 * repository.ts -- but still squarely inside Phase 12's own "portals"
 * scope, so closed out alongside it rather than left as a silent gap.
 */
class PortalDefinitions
{
    private const TAXPAYER_ROLES = ['TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER'];

    /** @return list<array{key: string, name: string, audience: string, description: string, href: string, capability: ?string, roles: list<string>}> */
    public static function all(): array
    {
        return [
            // NAMRA_SYSTEM_ADMIN, INFRASTRUCTURE_ADMIN, SUPER_ADMIN,
            // NAMRA_SYSTEM_SUPPORT and SECURITY_ANALYST all added at the
            // user's own explicit request -- all five are national/global-
            // scope roles with no organisation of their own, so
            // PortalService::capabilitySet's own special case is what
            // actually grants them the BUYER/SELLER capability this
            // portal's capability gate still requires; role-list
            // membership alone would not be enough.
            ['key' => 'buyer', 'name' => 'Buyer', 'audience' => 'Procurement and finance', 'description' => 'Purchases, input VAT, expenses, evidence and returns.', 'href' => '/portal/buyer', 'capability' => 'BUYER', 'roles' => [...self::TAXPAYER_ROLES, 'BUYER_ADMIN', 'BUYER_USER', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT', 'SECURITY_ANALYST']],
            ['key' => 'seller', 'name' => 'Seller', 'audience' => 'Sales and finance', 'description' => 'Quotations, sales, output VAT, inventory, projects and returns.', 'href' => '/portal/seller', 'capability' => 'SELLER', 'roles' => [...self::TAXPAYER_ROLES, 'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT', 'SECURITY_ANALYST']],
            ['key' => 'namra', 'name' => 'NamRA', 'audience' => 'Compliance, audit and refunds', 'description' => 'National work queues, taxpayer timelines, evidence and controlled decisions.', 'href' => '/portal/namra', 'capability' => null, 'roles' => ['NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_SUPPORT', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'SECURITY_ANALYST']],
            // Every one of these five also needs authority-governance:read
            // (see Permissions::ROLE_PERMISSIONS' own comment on each)
            // since this portal's own permission gate checks that
            // directly, not just role-list membership.
            ['key' => 'namra-admin', 'name' => 'NamRA Administration', 'audience' => 'Access administrators', 'description' => 'Identity, taxpayer activation, roles, memberships and provider posture.', 'href' => '/portal/namra-admin', 'capability' => null, 'roles' => ['NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT', 'SECURITY_ANALYST']],
            ['key' => 'super-admin', 'name' => 'Super Administration', 'audience' => 'Platform, SRE and security', 'description' => 'Technical health, integrations, eventing and security configuration without tax-data inheritance.', 'href' => '/portal/super-admin', 'capability' => null, 'roles' => ['SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SECURITY_ANALYST']],
            // TAXPAYER_OWNER deliberately excluded (see Permissions::
            // ROLE_PERMISSIONS' own comment on that role). SUPER_ADMIN,
            // INFRASTRUCTURE_ADMIN, NAMRA_SYSTEM_SUPPORT and
            // SECURITY_ANALYST all added per the user's own explicit
            // requests -- NAMRA_SYSTEM_SUPPORT stays national scope even
            // with this grant (Permissions::NATIONAL_SCOPE_ROLES is
            // unchanged for it), unlike the other three, which are all
            // global scope.
            ['key' => 'developer', 'name' => 'Developer and sandbox', 'audience' => 'Approved SaaS and ERP teams', 'description' => 'API clients, contracts, webhooks, quotas and conformance posture.', 'href' => '/portal/developer', 'capability' => null, 'roles' => ['TAXPAYER_ADMIN', 'SELLER_ADMIN', 'DEVELOPER_PARTNER', 'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'NAMRA_SYSTEM_SUPPORT', 'SECURITY_ANALYST']],
        ];
    }

    /** @param array<string, bool> $capabilities capability code => true, e.g. ['BUYER' => true] */
    public static function roleAllows(string $key, string $role, array $capabilities): bool
    {
        $portal = null;
        foreach (self::all() as $candidate) {
            if ($candidate['key'] === $key) {
                $portal = $candidate;
                break;
            }
        }
        if (! $portal) {
            return false;
        }

        return in_array($role, $portal['roles'], true) && (! $portal['capability'] || ($capabilities[$portal['capability']] ?? false));
    }
}

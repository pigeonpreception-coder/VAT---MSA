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
            // NAMRA_SYSTEM_ADMIN, INFRASTRUCTURE_ADMIN, SUPER_ADMIN and
            // NAMRA_SYSTEM_SUPPORT all added at the user's own explicit
            // request -- all four are national/global-scope roles with no
            // organisation of their own, so PortalService::capabilitySet's
            // own special case is what actually grants them the BUYER/
            // SELLER capability this portal's capability gate still
            // requires; role-list membership alone would not be enough.
            ['key' => 'buyer', 'name' => 'Buyer', 'audience' => 'Procurement and finance', 'description' => 'Purchases, input VAT, expenses, evidence and returns.', 'href' => '/portal/buyer', 'capability' => 'BUYER', 'roles' => [...self::TAXPAYER_ROLES, 'BUYER_ADMIN', 'BUYER_USER', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT']],
            ['key' => 'seller', 'name' => 'Seller', 'audience' => 'Sales and finance', 'description' => 'Quotations, sales, output VAT, inventory, projects and returns.', 'href' => '/portal/seller', 'capability' => 'SELLER', 'roles' => [...self::TAXPAYER_ROLES, 'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT']],
            // NamRA System Support (formerly PILOT_ADMIN, then NAMRA_STAFF)
            // is on this list plus buyer/seller/namra-admin now -- still not
            // super-admin/developer -- per the user's own explicit request;
            // see Permissions::ROLE_PERMISSIONS' own comment on this role.
            // NAMRA_SYSTEM_ADMIN/INFRASTRUCTURE_ADMIN/SUPER_ADMIN added per
            // the user's own explicit requests too.
            ['key' => 'namra', 'name' => 'NamRA', 'audience' => 'Compliance, audit and refunds', 'description' => 'National work queues, taxpayer timelines, evidence and controlled decisions.', 'href' => '/portal/namra', 'capability' => null, 'roles' => ['NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_SUPPORT', 'NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN']],
            // INFRASTRUCTURE_ADMIN/SUPER_ADMIN/NAMRA_SYSTEM_SUPPORT added
            // per the user's own explicit request -- all three also need
            // authority-governance:read (see Permissions::ROLE_PERMISSIONS'
            // own comment on each) since this portal's own permission gate
            // checks that directly, not just role-list membership.
            ['key' => 'namra-admin', 'name' => 'NamRA Administration', 'audience' => 'Access administrators', 'description' => 'Identity, taxpayer activation, roles, memberships and provider posture.', 'href' => '/portal/namra-admin', 'capability' => null, 'roles' => ['NAMRA_SYSTEM_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SUPER_ADMIN', 'NAMRA_SYSTEM_SUPPORT']],
            ['key' => 'super-admin', 'name' => 'Super Administration', 'audience' => 'Platform, SRE and security', 'description' => 'Technical health, integrations, eventing and security configuration without tax-data inheritance.', 'href' => '/portal/super-admin', 'capability' => null, 'roles' => ['SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SECURITY_ANALYST']],
            // TAXPAYER_OWNER deliberately excluded (see Permissions::
            // ROLE_PERMISSIONS' own comment on that role); SUPER_ADMIN
            // reaches every portal (its own explicit "all six" request).
            // INFRASTRUCTURE_ADMIN and NAMRA_SYSTEM_SUPPORT deliberately
            // excluded here even though both now reach every other portal
            // -- the user's own explicit requests named this one exception
            // for each.
            ['key' => 'developer', 'name' => 'Developer and sandbox', 'audience' => 'Approved SaaS and ERP teams', 'description' => 'API clients, contracts, webhooks, quotas and conformance posture.', 'href' => '/portal/developer', 'capability' => null, 'roles' => ['TAXPAYER_ADMIN', 'SELLER_ADMIN', 'DEVELOPER_PARTNER', 'SUPER_ADMIN']],
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

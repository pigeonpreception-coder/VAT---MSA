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
            ['key' => 'buyer', 'name' => 'Buyer', 'audience' => 'Procurement and finance', 'description' => 'Purchases, input VAT, expenses, evidence and returns.', 'href' => '/portal/buyer', 'capability' => 'BUYER', 'roles' => [...self::TAXPAYER_ROLES, 'BUYER_ADMIN', 'BUYER_USER', 'PILOT_ADMIN']],
            ['key' => 'seller', 'name' => 'Seller', 'audience' => 'Sales and finance', 'description' => 'Quotations, sales, output VAT, inventory, projects and returns.', 'href' => '/portal/seller', 'capability' => 'SELLER', 'roles' => [...self::TAXPAYER_ROLES, 'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'PILOT_ADMIN']],
            ['key' => 'namra', 'name' => 'NamRA', 'audience' => 'Compliance, audit and refunds', 'description' => 'National work queues, taxpayer timelines, evidence and controlled decisions.', 'href' => '/portal/namra', 'capability' => null, 'roles' => ['NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'PILOT_ADMIN']],
            ['key' => 'namra-admin', 'name' => 'NamRA Administration', 'audience' => 'Access administrators', 'description' => 'Identity, taxpayer activation, roles, memberships and provider posture.', 'href' => '/portal/namra-admin', 'capability' => null, 'roles' => ['NAMRA_SYSTEM_ADMIN', 'PILOT_ADMIN']],
            ['key' => 'super-admin', 'name' => 'Super Administration', 'audience' => 'Platform, SRE and security', 'description' => 'Technical health, integrations, eventing and security configuration without tax-data inheritance.', 'href' => '/portal/super-admin', 'capability' => null, 'roles' => ['SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SECURITY_ANALYST', 'PILOT_ADMIN']],
            ['key' => 'developer', 'name' => 'Developer and sandbox', 'audience' => 'Approved SaaS and ERP teams', 'description' => 'API clients, contracts, webhooks, quotas and conformance posture.', 'href' => '/portal/developer', 'capability' => null, 'roles' => ['TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'SELLER_ADMIN', 'DEVELOPER_PARTNER', 'PILOT_ADMIN']],
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

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ported from db/runtime.ts's access_roles seed rows (SECURITY_SEED_STATEMENTS).
 *
 * NOTE -- genuine gap found in the original source, not invented here: the
 * TS seed data never inserted SELLER_ADMIN/SELLER_OPERATOR/SELLER_VIEWER or
 * BUYER_ADMIN/BUYER_USER into access_roles at all, even though
 * lib/domain/access.ts's ROLE_PERMISSIONS grants all five real permissions.
 * app_users.role there is a plain unconstrained TEXT column (no FK to
 * access_roles), so the gap was silently tolerated; here role_code DOES
 * carry a real FK (role_permission_grants, organisation_memberships), so
 * completing the registry is required for referential integrity, not
 * optional. Audience/risk_tier for these five are inferred consistently
 * with the seeded rows' own pattern (TAXPAYER-tier commercial roles),
 * flagged here rather than silently presented as verified source data.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $roles = [
            // NAMRA_SYSTEM_SUPPORT (formerly PILOT_ADMIN, then NAMRA_STAFF,
            // renamed twice at the user's own explicit request -- see
            // Permissions::ROLE_PERMISSIONS' own comment on this role):
            // name/audience/risk_tier updated to reflect its scope (Buyer/
            // Seller/NamRA/NamRA Administration, no Super Administration or
            // Developer) -- a deliberate deviation from the otherwise-
            // verbatim db/runtime.ts source row, not an inherited value.
            // Every row below this one is still verbatim from db/runtime.ts
            // SECURITY_SEED_STATEMENTS / CONTROL_PLANE_SEED_STATEMENTS.
            ['NAMRA_SYSTEM_SUPPORT', 'NamRA System Support', 'NAMRA', 'HIGH'],
            ['TAXPAYER_OWNER', 'Taxpayer Owner', 'TAXPAYER', 'HIGH'],
            ['TAXPAYER_ADMIN', 'Taxpayer Administrator', 'TAXPAYER', 'HIGH'],
            ['TAXPAYER_ACCOUNTANT', 'Taxpayer Accountant', 'TAXPAYER', 'MEDIUM'],
            ['TAXPAYER_STAFF', 'Taxpayer Staff', 'TAXPAYER', 'MEDIUM'],
            ['TAXPAYER_VIEWER', 'Taxpayer Viewer', 'TAXPAYER', 'LOW'],
            ['NAMRA_COMPLIANCE_OFFICER', 'NamRA Compliance Officer', 'NAMRA', 'HIGH'],
            ['NAMRA_AUDITOR', 'NamRA Auditor', 'NAMRA', 'HIGH'],
            ['INTERNAL_AUDITOR', 'Internal Auditor', 'ASSURANCE', 'HIGH'],
            ['SECURITY_ANALYST', 'Security Analyst', 'SECURITY', 'HIGH'],
            ['NAMRA_REFUND_OFFICER', 'NamRA Refund Officer', 'NAMRA', 'HIGH'],
            ['NAMRA_SUPERVISOR', 'NamRA Supervisor', 'NAMRA', 'CRITICAL'],
            ['NAMRA_SYSTEM_ADMIN', 'NamRA System Administrator', 'NAMRA_ADMIN', 'CRITICAL'],
            ['SUPER_ADMIN', 'Super Administrator', 'PLATFORM', 'CRITICAL'],
            ['INFRASTRUCTURE_ADMIN', 'Infrastructure Administrator', 'PLATFORM', 'CRITICAL'],
            ['DEVELOPER_PARTNER', 'Developer Partner', 'PARTNER', 'HIGH'],
            // Completed here (see class doc comment) -- never seeded in the TS source.
            ['SELLER_ADMIN', 'Seller Administrator', 'TAXPAYER', 'HIGH'],
            ['SELLER_OPERATOR', 'Seller Operator', 'TAXPAYER', 'MEDIUM'],
            ['SELLER_VIEWER', 'Seller Viewer', 'TAXPAYER', 'LOW'],
            ['BUYER_ADMIN', 'Buyer Administrator', 'TAXPAYER', 'HIGH'],
            ['BUYER_USER', 'Buyer User', 'TAXPAYER', 'MEDIUM'],
        ];

        foreach ($roles as [$code, $name, $audience, $riskTier]) {
            DB::table('access_roles')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'audience' => $audience, 'risk_tier' => $riskTier, 'status' => 'ACTIVE', 'created_at' => $now],
            );
        }
    }
}

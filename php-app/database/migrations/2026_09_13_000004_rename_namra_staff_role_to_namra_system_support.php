<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the NAMRA_STAFF role to NAMRA_SYSTEM_SUPPORT (user's own explicit
 * request, made shortly after the PILOT_ADMIN -> NAMRA_STAFF rename this
 * role has already been through once -- see
 * 2026_09_13_000003_rename_pilot_admin_role_to_namra_staff.php, which this
 * migration mirrors exactly), and grants it Buyer/Seller/NamRA
 * Administration portal access at the same time -- see
 * App\Support\Access\Permissions::ROLE_PERMISSIONS' own comment on the
 * NAMRA_SYSTEM_SUPPORT entry, and App\Domain\Portal\PortalDefinitions' own
 * comment, for the access changes themselves. This migration only handles
 * the data migration those code changes require: `users.role` is a real
 * MySQL enum, so an already-migrated database (this one included) has
 * existing rows storing the literal string 'NAMRA_STAFF' -- editing that
 * earlier migration's own final enum list would not affect them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'NAMRA_STAFF', 'NAMRA_SYSTEM_SUPPORT', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");

        DB::table('users')->where('role', 'NAMRA_STAFF')->update(['role' => 'NAMRA_SYSTEM_SUPPORT']);

        foreach (['organisation_memberships', 'role_permission_grants', 'user_invitations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('role_code', 'NAMRA_STAFF')->update(['role_code' => 'NAMRA_SYSTEM_SUPPORT']);
            }
        }

        DB::table('access_roles')->where('code', 'NAMRA_STAFF')->update([
            'code' => 'NAMRA_SYSTEM_SUPPORT', 'name' => 'NamRA System Support', 'audience' => 'NAMRA', 'risk_tier' => 'HIGH',
        ]);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'NAMRA_SYSTEM_SUPPORT', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'NAMRA_STAFF', 'NAMRA_SYSTEM_SUPPORT', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");

        DB::table('users')->where('role', 'NAMRA_SYSTEM_SUPPORT')->update(['role' => 'NAMRA_STAFF']);

        foreach (['organisation_memberships', 'role_permission_grants', 'user_invitations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('role_code', 'NAMRA_SYSTEM_SUPPORT')->update(['role_code' => 'NAMRA_STAFF']);
            }
        }

        DB::table('access_roles')->where('code', 'NAMRA_SYSTEM_SUPPORT')->update([
            'code' => 'NAMRA_STAFF', 'name' => 'NamRA Staff', 'audience' => 'NAMRA', 'risk_tier' => 'HIGH',
        ]);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'NAMRA_STAFF', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");
    }
};

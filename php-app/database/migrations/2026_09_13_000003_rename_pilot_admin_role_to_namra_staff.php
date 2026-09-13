<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the PILOT_ADMIN role to NAMRA_STAFF (user's own explicit
 * request), and narrows its portal access at the same time -- see
 * App\Support\Access\Permissions::ROLE_PERMISSIONS' own comment on the
 * NAMRA_STAFF entry, and App\Domain\Portal\PortalDefinitions' own comment,
 * for the access changes themselves. This migration only handles the data
 * migration those code changes require: `users.role` is a real MySQL enum
 * (0001_01_01_000000_b_create_users_table.php), so an already-migrated
 * database (this one included) has existing rows storing the literal string
 * 'PILOT_ADMIN' -- editing that historical migration file would not affect
 * them. Order matters: every table with a real FK to `access_roles.code`
 * must stop referencing the old code before that primary key value is
 * renamed, and the `users.role` enum must gain the new value before any
 * row is updated to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'PILOT_ADMIN', 'NAMRA_STAFF', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");

        DB::table('users')->where('role', 'PILOT_ADMIN')->update(['role' => 'NAMRA_STAFF']);

        // Defensive: no seeder ever creates a PILOT_ADMIN row in any of
        // these three tables (each one FKs role_code to access_roles.code),
        // but a real deployment could have created one since, and the
        // access_roles rename just below would otherwise fail on the FK.
        foreach (['organisation_memberships', 'role_permission_grants', 'user_invitations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('role_code', 'PILOT_ADMIN')->update(['role_code' => 'NAMRA_STAFF']);
            }
        }

        DB::table('access_roles')->where('code', 'PILOT_ADMIN')->update([
            'code' => 'NAMRA_STAFF', 'name' => 'NamRA Staff', 'audience' => 'NAMRA', 'risk_tier' => 'HIGH',
        ]);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'NAMRA_STAFF', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'PILOT_ADMIN', 'NAMRA_STAFF', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");

        DB::table('users')->where('role', 'NAMRA_STAFF')->update(['role' => 'PILOT_ADMIN']);

        foreach (['organisation_memberships', 'role_permission_grants', 'user_invitations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('role_code', 'NAMRA_STAFF')->update(['role_code' => 'PILOT_ADMIN']);
            }
        }

        DB::table('access_roles')->where('code', 'NAMRA_STAFF')->update([
            'code' => 'PILOT_ADMIN', 'name' => 'Pilot Administrator', 'audience' => 'PLATFORM', 'risk_tier' => 'CRITICAL',
        ]);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'PILOT_ADMIN', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
            'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
            'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
            'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST'
        ) NOT NULL");
    }
};

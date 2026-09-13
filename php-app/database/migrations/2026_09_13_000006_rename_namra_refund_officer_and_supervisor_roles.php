<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames NAMRA_REFUND_OFFICER to NAMRA_VAT_SENIOR_AUDITOR and
 * NAMRA_SUPERVISOR to NAMRA_VAT_SUPERVISOR (user's own explicit request).
 * Same enum-swap/access_roles-rename/defensive-FK-update mechanics as
 * 2026_09_13_000003_rename_pilot_admin_role_to_namra_staff.php and
 * 2026_09_13_000004_rename_namra_staff_role_to_namra_system_support.php --
 * `users.role` is a real MySQL enum, so an already-migrated database (this
 * one included) has existing rows storing the literal old strings, which
 * editing an earlier migration's own final enum list would not affect.
 * Neither role's own Permissions::ROLE_PERMISSIONS grant set changed --
 * only the role code (and this row's own `access_roles.name`) moved.
 */
return new class extends Migration
{
    private const OLD_ENUM = [
        'NAMRA_SYSTEM_SUPPORT', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
        'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
        'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
        'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
    ];

    private const NEW_ENUM = [
        'NAMRA_SYSTEM_SUPPORT', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
        'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
        'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_VAT_SENIOR_AUDITOR', 'NAMRA_VAT_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
        'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
    ];

    /** @param array<string, string> $renames old code => new code */
    private function renameRoles(array $renames, array $enumDuringTransition, array $finalEnum): void
    {
        $quoted = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM({$quoted($enumDuringTransition)}) NOT NULL");

        foreach ($renames as $old => $new) {
            DB::table('users')->where('role', $old)->update(['role' => $new]);

            foreach (['organisation_memberships', 'role_permission_grants', 'user_invitations'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('role_code', $old)->update(['role_code' => $new]);
                }
            }
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM({$quoted($finalEnum)}) NOT NULL");
    }

    public function up(): void
    {
        $this->renameRoles(
            ['NAMRA_REFUND_OFFICER' => 'NAMRA_VAT_SENIOR_AUDITOR', 'NAMRA_SUPERVISOR' => 'NAMRA_VAT_SUPERVISOR'],
            [...self::OLD_ENUM, 'NAMRA_VAT_SENIOR_AUDITOR', 'NAMRA_VAT_SUPERVISOR'],
            self::NEW_ENUM,
        );

        DB::table('access_roles')->where('code', 'NAMRA_REFUND_OFFICER')->update([
            'code' => 'NAMRA_VAT_SENIOR_AUDITOR', 'name' => 'NamRA VAT Senior Auditor',
        ]);
        DB::table('access_roles')->where('code', 'NAMRA_SUPERVISOR')->update([
            'code' => 'NAMRA_VAT_SUPERVISOR', 'name' => 'NamRA VAT Supervisor',
        ]);
    }

    public function down(): void
    {
        $this->renameRoles(
            ['NAMRA_VAT_SENIOR_AUDITOR' => 'NAMRA_REFUND_OFFICER', 'NAMRA_VAT_SUPERVISOR' => 'NAMRA_SUPERVISOR'],
            [...self::NEW_ENUM, 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR'],
            self::OLD_ENUM,
        );

        DB::table('access_roles')->where('code', 'NAMRA_VAT_SENIOR_AUDITOR')->update([
            'code' => 'NAMRA_REFUND_OFFICER', 'name' => 'NamRA Refund Officer',
        ]);
        DB::table('access_roles')->where('code', 'NAMRA_VAT_SUPERVISOR')->update([
            'code' => 'NAMRA_SUPERVISOR', 'name' => 'NamRA Supervisor',
        ]);
    }
};

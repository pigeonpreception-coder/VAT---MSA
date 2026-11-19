<?php

namespace App\Support\Access;

use App\Support\Ui\Humanize;

/**
 * Display names for the built-in role codes in Permissions::ROLE_PERMISSIONS.
 * National roles drop the NAMRA_ prefix -- the authority is shown alongside
 * the role wherever context is needed, and the prefix is not tenant-neutral.
 */
final class RoleLabels
{
    public const LABELS = [
        'TAXPAYER_OWNER' => 'Owner',
        'TAXPAYER_ADMIN' => 'Administrator',
        'TAXPAYER_ACCOUNTANT' => 'Accountant',
        'TAXPAYER_STAFF' => 'Staff',
        'TAXPAYER_VIEWER' => 'Viewer',
        'SELLER_ADMIN' => 'Seller Administrator',
        'SELLER_OPERATOR' => 'Seller Operator',
        'SELLER_VIEWER' => 'Seller Viewer',
        'BUYER_ADMIN' => 'Buyer Administrator',
        'BUYER_USER' => 'Buyer',
        'NAMRA_SYSTEM_SUPPORT' => 'System Support',
        'NAMRA_COMPLIANCE_OFFICER' => 'Compliance Officer',
        'NAMRA_VAT_AUDITOR' => 'VAT Auditor',
        'NAMRA_VAT_SENIOR_AUDITOR' => 'Senior VAT Auditor',
        'NAMRA_VAT_SUPERVISOR' => 'VAT Supervisor',
        'NAMRA_SYSTEM_ADMIN' => 'System Administrator',
        'SUPER_ADMIN' => 'Super Administrator',
        'INFRASTRUCTURE_ADMIN' => 'Infrastructure Administrator',
        'DEVELOPER_PARTNER' => 'Developer Partner',
        'INTERNAL_AUDITOR' => 'Internal Auditor',
        'SECURITY_ANALYST' => 'Security Analyst',
    ];

    public static function for(?string $role): string
    {
        if ($role === null || $role === '') {
            return '';
        }

        return self::LABELS[$role] ?? Humanize::title($role);
    }
}

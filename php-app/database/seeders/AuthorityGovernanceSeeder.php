<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ported verbatim from db/runtime.ts's countries/tax_jurisdictions/
 * tax_authorities/tax_authority_role_definitions seed rows -- deploy-time
 * reference data, matching IdentityProviderSeeder's own precedent
 * (genuine platform configuration, not demo-user/organisation fixture
 * data, so kept as its own seeder rather than folded into DemoSeeder).
 * IDs are the source's own stable, human-readable seed IDs (e.g.
 * 'tax-authority-na-namra'), not generated UUIDs -- see
 * App\Models\TaxAuthority's own doc comment.
 */
class AuthorityGovernanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('countries')->updateOrInsert(
            ['code' => 'NA'],
            ['iso3_code' => 'NAM', 'name' => 'Namibia', 'currency_code' => 'NAD', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        DB::table('tax_jurisdictions')->updateOrInsert(
            ['id' => 'tax-jurisdiction-na-national'],
            ['country_code' => 'NA', 'code' => 'NA-NATIONAL', 'name' => 'Namibia national tax jurisdiction', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        DB::table('tax_authorities')->updateOrInsert(
            ['id' => 'tax-authority-na-namra'],
            ['jurisdiction_id' => 'tax-jurisdiction-na-national', 'code' => 'NAMRA', 'name' => 'Namibia Revenue Agency', 'status' => 'ACTIVE', 'created_at' => now()],
        );

        $roles = [
            ['code' => 'AUTHORITY_ONBOARDING_MAKER', 'name' => 'Authority Onboarding Maker', 'duty_class' => 'ONBOARDING_MAKER'],
            ['code' => 'AUTHORITY_SECURITY_REVIEWER', 'name' => 'Authority Security Reviewer', 'duty_class' => 'SECURITY_REVIEW'],
            ['code' => 'AUTHORITY_PRIVACY_REVIEWER', 'name' => 'Authority Privacy Reviewer', 'duty_class' => 'PRIVACY_REVIEW'],
            ['code' => 'AUTHORITY_LEGAL_REVIEWER', 'name' => 'Authority Legal Reviewer', 'duty_class' => 'LEGAL_REVIEW'],
            ['code' => 'AUTHORITY_INTEGRATION_REVIEWER', 'name' => 'Authority Integration Reviewer', 'duty_class' => 'INTEGRATION_REVIEW'],
            ['code' => 'AUTHORITY_ACTIVATION_APPROVER', 'name' => 'Authority Activation Approver', 'duty_class' => 'ACTIVATION_APPROVAL'],
            ['code' => 'AUTHORITY_ACCESS_REVIEWER', 'name' => 'Authority Access Reviewer', 'duty_class' => 'ACCESS_REVIEW'],
            ['code' => 'AUTHORITY_SYSTEM_ADMIN', 'name' => 'Authority System Administrator', 'duty_class' => 'SYSTEM_ADMINISTRATION'],
            ['code' => 'AUTHORITY_GOVERNANCE_AUDITOR', 'name' => 'Authority Governance Auditor', 'duty_class' => 'AUDIT', 'assurance_required' => 'MFA'],
        ];
        foreach ($roles as $role) {
            DB::table('tax_authority_role_definitions')->updateOrInsert(
                ['code' => $role['code']],
                [
                    'name' => $role['name'], 'duty_class' => $role['duty_class'],
                    'assurance_required' => $role['assurance_required'] ?? 'PHISHING_RESISTANT_MFA',
                    'protected' => true, 'status' => 'ACTIVE', 'created_at' => now(),
                ],
            );
        }
    }
}

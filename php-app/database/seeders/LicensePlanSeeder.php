<?php

namespace Database\Seeders;

use App\Models\LicenseFeature;
use App\Models\LicensePlan;
use App\Models\LicensePlanEntitlement;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's license_plans/license_features/
 * license_plan_entitlements seed rows -- like TaxRuleSetSeeder, a real
 * functional prerequisite rather than cosmetic demo data:
 * LicensingService has no command path that can ever create a plan or
 * feature (see the license_plans migration's own doc comment), so without
 * this seed no organisation could ever be entitled to anything in either
 * the source or this port. IDs are kept as the source's own slugs,
 * matching VatRuleSeeder's/TaxRuleSetSeeder's own convention.
 */
class LicensePlanSeeder extends Seeder
{
    public function run(): void
    {
        LicensePlan::updateOrCreate(['id' => 'plan-pilot-professional-v1'], [
            'code' => 'PILOT_PROFESSIONAL', 'name' => 'Professional Pilot', 'version' => 1, 'status' => 'ACTIVE',
            'effective_from' => '2026-08-01 00:00:00', 'effective_to' => null, 'created_at' => '2026-08-10 10:00:00',
        ]);

        $features = [
            ['feature_key' => 'CORE_VAT', 'name' => 'Core VAT management', 'description' => 'Controlled invoice VAT reconciliation and return workspaces', 'metric_key' => null, 'protected' => true],
            ['feature_key' => 'ADMINISTRATION', 'name' => 'Organisation administration', 'description' => 'Employees roles access governance and security posture', 'metric_key' => 'USER_SEATS', 'protected' => true],
            ['feature_key' => 'USER_SEATS', 'name' => 'User seats', 'description' => 'Active organisation users', 'metric_key' => 'USER_SEATS', 'protected' => false],
            ['feature_key' => 'BRANCHES', 'name' => 'Branches', 'description' => 'Active operating branches', 'metric_key' => 'BRANCHES', 'protected' => false],
            ['feature_key' => 'ADVANCED_WORKFLOW', 'name' => 'Advanced workflow', 'description' => 'Versioned conditional workflow and access governance', 'metric_key' => 'WORKFLOWS', 'protected' => true],
            ['feature_key' => 'ACCOUNTING', 'name' => 'Accounting', 'description' => 'General ledger and financial controls', 'metric_key' => null, 'protected' => false],
            ['feature_key' => 'INVENTORY', 'name' => 'Inventory', 'description' => 'Inventory and warehouse controls', 'metric_key' => null, 'protected' => false],
            ['feature_key' => 'PROJECTS', 'name' => 'Projects', 'description' => 'Project costing budgets and reports', 'metric_key' => null, 'protected' => false],
            ['feature_key' => 'ANALYTICS', 'name' => 'Analytics', 'description' => 'Advanced governed reports and analytics', 'metric_key' => 'REPORT_RUNS', 'protected' => false],
            ['feature_key' => 'API_ACCESS', 'name' => 'API access', 'description' => 'Scoped API clients webhooks and usage', 'metric_key' => 'API_REQUESTS', 'protected' => true],
        ];
        foreach ($features as $feature) {
            LicenseFeature::updateOrCreate(['feature_key' => $feature['feature_key']], array_merge($feature, ['created_at' => '2026-08-10 10:00:00']));
        }

        $entitlements = [
            ['id' => 'ent-core', 'feature_key' => 'CORE_VAT', 'limit_value' => null, 'configuration' => '{}'],
            ['id' => 'ent-admin', 'feature_key' => 'ADMINISTRATION', 'limit_value' => null, 'configuration' => '{}'],
            ['id' => 'ent-seats', 'feature_key' => 'USER_SEATS', 'limit_value' => 25, 'configuration' => '{}'],
            ['id' => 'ent-branches', 'feature_key' => 'BRANCHES', 'limit_value' => 5, 'configuration' => '{}'],
            ['id' => 'ent-workflow', 'feature_key' => 'ADVANCED_WORKFLOW', 'limit_value' => 20, 'configuration' => '{"max_nodes":30}'],
            ['id' => 'ent-accounting', 'feature_key' => 'ACCOUNTING', 'limit_value' => null, 'configuration' => '{}'],
            ['id' => 'ent-inventory', 'feature_key' => 'INVENTORY', 'limit_value' => null, 'configuration' => '{}'],
            ['id' => 'ent-projects', 'feature_key' => 'PROJECTS', 'limit_value' => null, 'configuration' => '{}'],
            ['id' => 'ent-analytics', 'feature_key' => 'ANALYTICS', 'limit_value' => 1000, 'configuration' => '{}'],
            ['id' => 'ent-api', 'feature_key' => 'API_ACCESS', 'limit_value' => 100000, 'configuration' => '{}'],
        ];
        foreach ($entitlements as $entitlement) {
            LicensePlanEntitlement::updateOrCreate(['id' => $entitlement['id']], array_merge($entitlement, [
                'license_plan_id' => 'plan-pilot-professional-v1', 'enabled' => true,
            ]));
        }
    }
}

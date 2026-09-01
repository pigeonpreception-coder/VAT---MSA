<?php

namespace Database\Seeders;

use App\Models\OrganisationAdministratorRole;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's organisation_administrator_roles
 * seed rows -- a fixed, code-versioned catalogue like
 * OrganisationAdministratorRoleSeeder's own sibling seeders
 * (VatRuleSeeder/TaxRuleSetSeeder/LicensePlanSeeder): AppointAdministrator
 * has no command path that can ever create a new administrator role, so
 * without this seed no organisation could ever appoint one in either
 * system.
 */
class OrganisationAdministratorRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'PRIMARY', 'name' => 'Primary Organisation Administrator', 'maximum_scope' => 'ORGANISATION'],
            ['code' => 'FINANCE', 'name' => 'Finance Administrator', 'maximum_scope' => 'FINANCE_SCOPE'],
            ['code' => 'USER_ACCESS', 'name' => 'User and Access Administrator', 'maximum_scope' => 'IDENTITY_SCOPE'],
            ['code' => 'BRANCH', 'name' => 'Branch Administrator', 'maximum_scope' => 'BRANCH_SCOPE'],
            ['code' => 'WORKFLOW', 'name' => 'Workflow Administrator', 'maximum_scope' => 'WORKFLOW_SCOPE'],
            ['code' => 'INTEGRATION', 'name' => 'Integration Administrator', 'maximum_scope' => 'INTEGRATION_SCOPE'],
        ];
        foreach ($roles as $role) {
            OrganisationAdministratorRole::updateOrCreate(['code' => $role['code']], array_merge($role, ['protected' => true]));
        }
    }
}

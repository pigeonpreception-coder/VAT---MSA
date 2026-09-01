<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed order matters: roles/permissions are the identity/access
     * foundation every later seeder (organisations, employees, VAT,
     * licensing, ...) will depend on.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            VatRuleSeeder::class,
            TaxRuleSetSeeder::class,
            LicensePlanSeeder::class,
            OrganisationAdministratorRoleSeeder::class,
            DemoSeeder::class,
        ]);
    }
}

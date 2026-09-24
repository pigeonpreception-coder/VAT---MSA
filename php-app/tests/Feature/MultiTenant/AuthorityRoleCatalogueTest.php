<?php

namespace Tests\Feature\MultiTenant;

use App\Models\Country;
use App\Models\Organisation;
use App\Models\TaxAuthority;
use App\Models\TaxAuthorityRolePermission;
use App\Models\Taxpayer;
use App\Models\User;
use App\Support\Access\AuthorityRolePermissions;
use App\Support\Access\Permissions;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multi-tenant SaaS pivot phase 6 (2026-09-24): covers
 * `App\Support\Access\AuthorityRolePermissions` -- the per-tenant role-
 * catalogue mechanism every prior phase's own MIGRATION_MATRIX.md entry
 * has deferred to this phase. Follows this pivot's own established
 * two-halves pattern (see JurisdictionAwareCalculationTest's own doc
 * comment): a second, wholly fictitious tax authority (country 'ZT') with
 * a custom permission set for a built-in role proves the override is
 * genuinely dynamic, and a parallel NamRA case on the same role code
 * proves today's only real tenant sees zero behaviour change.
 */
class AuthorityRoleCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const NAMRA_AUTHORITY_ID = 'tax-authority-na-namra';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function makeSecondTaxAuthority(): TaxAuthority
    {
        Country::create(['code' => 'ZT', 'iso3_code' => 'ZTS', 'name' => 'Zambesi Test', 'currency_code' => 'ZTD', 'status' => 'ACTIVE']);
        DB::table('tax_jurisdictions')->insert(['id' => 'tax-jurisdiction-zt-national', 'country_code' => 'ZT', 'code' => 'ZT-NATIONAL', 'name' => 'Zambesi Test national jurisdiction', 'status' => 'ACTIVE', 'created_at' => now()]);
        DB::table('tax_authorities')->insert(['id' => 'tax-authority-zt-test', 'jurisdiction_id' => 'tax-jurisdiction-zt-national', 'code' => 'ZTRA', 'short_name' => 'ZTRA', 'name' => 'Zambesi Test Revenue Authority', 'status' => 'ACTIVE', 'created_at' => now()]);

        return TaxAuthority::findOrFail('tax-authority-zt-test');
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, staff: User} */
    private function makeStaffUser(string $vatNumber, string $taxAuthorityId, string $role = 'TAXPAYER_STAFF'): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'tax_authority_id' => $taxAuthorityId,
            'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Staff", 'email' => strtolower($vatNumber).'-staff@test.test',
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'staff');
    }

    public function test_a_role_with_no_authority_catalogue_resolves_the_static_permission_set_exactly(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        ['staff' => $staff] = $this->makeStaffUser('VAT-ZT-ROLE-0001', $authority->id);

        $this->assertFalse(AuthorityRolePermissions::hasCatalogue($authority->id, 'TAXPAYER_STAFF'));
        foreach (Permissions::effectiveForRole('TAXPAYER_STAFF') as $permission) {
            $this->assertTrue($staff->hasAppPermission($permission), "Expected {$permission} to still resolve with no authority override.");
        }
        $this->assertFalse($staff->hasAppPermission('vat-rules:manage'));
    }

    public function test_an_authority_defined_catalogue_replaces_the_static_permission_set_for_its_own_organisations(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        ['staff' => $staff] = $this->makeStaffUser('VAT-ZT-ROLE-0002', $authority->id);

        // A deliberately narrower catalogue than the static TAXPAYER_STAFF
        // set: drops 'inventory:manage' (which the static role grants) and
        // proves the override is a genuine replacement, not an additive
        // grant layered on top of the static map.
        $this->assertTrue(in_array('inventory:manage', Permissions::effectiveForRole('TAXPAYER_STAFF'), true));
        TaxAuthorityRolePermission::create(['tax_authority_id' => $authority->id, 'role_code' => 'TAXPAYER_STAFF', 'permission_code' => 'invoices:read']);
        TaxAuthorityRolePermission::create(['tax_authority_id' => $authority->id, 'role_code' => 'TAXPAYER_STAFF', 'permission_code' => 'invoices:submit']);

        $staff->refresh();
        $this->assertTrue($staff->hasAppPermission('invoices:read'));
        $this->assertTrue($staff->hasAppPermission('invoices:submit'));
        $this->assertFalse($staff->hasAppPermission('inventory:manage'));
        $this->assertSame(['invoices:read', 'invoices:submit'], AuthorityRolePermissions::forRole($authority->id, 'TAXPAYER_STAFF'));
    }

    /** The current, only real tenant (NamRA) must see zero behaviour change: no rows exist for its authority id. */
    public function test_namra_organisations_are_unaffected_by_a_second_authoritys_catalogue(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        TaxAuthorityRolePermission::create(['tax_authority_id' => $authority->id, 'role_code' => 'TAXPAYER_STAFF', 'permission_code' => 'invoices:read']);

        ['staff' => $namraStaff] = $this->makeStaffUser('VAT-NA-ROLE-0001', self::NAMRA_AUTHORITY_ID);

        $this->assertFalse(AuthorityRolePermissions::hasCatalogue(self::NAMRA_AUTHORITY_ID, 'TAXPAYER_STAFF'));
        foreach (Permissions::effectiveForRole('TAXPAYER_STAFF') as $permission) {
            $this->assertTrue($namraStaff->hasAppPermission($permission));
        }
        $this->assertSame(
            Permissions::roleHas('TAXPAYER_STAFF', 'invoices:read'),
            $namraStaff->hasAppPermission('invoices:read'),
        );
    }

    public function test_a_national_scope_user_with_no_organisation_always_resolves_the_static_map(): void
    {
        $authority = $this->makeSecondTaxAuthority();
        TaxAuthorityRolePermission::create(['tax_authority_id' => $authority->id, 'role_code' => 'NAMRA_SYSTEM_SUPPORT', 'permission_code' => 'identity:read']);

        $national = User::create([
            'id' => (string) Str::uuid(), 'name' => 'National Staff', 'email' => 'national-staff@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->assertNull($national->taxAuthorityId());
        foreach (Permissions::effectiveForRole('NAMRA_SYSTEM_SUPPORT') as $permission) {
            $this->assertTrue($national->hasAppPermission($permission));
        }
    }

    public function test_a_third_organisations_authority_never_sees_another_authoritys_catalogue(): void
    {
        $zt = $this->makeSecondTaxAuthority();
        TaxAuthorityRolePermission::create(['tax_authority_id' => $zt->id, 'role_code' => 'TAXPAYER_STAFF', 'permission_code' => 'invoices:read']);

        ['staff' => $namraStaff] = $this->makeStaffUser('VAT-NA-ROLE-0002', self::NAMRA_AUTHORITY_ID);
        $this->assertTrue($namraStaff->hasAppPermission('inventory:manage'));

        $this->assertSame(['invoices:read'], AuthorityRolePermissions::forRole($zt->id, 'TAXPAYER_STAFF'));
        $this->assertNotEquals(
            AuthorityRolePermissions::forRole($zt->id, 'TAXPAYER_STAFF'),
            AuthorityRolePermissions::forRole(self::NAMRA_AUTHORITY_ID, 'TAXPAYER_STAFF'),
        );
    }
}

<?php

namespace Tests\Feature\Operations;

use App\Models\FixedAsset;
use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationLicense;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Operations > ERP Module
 * (App\Http\Controllers\Operations\ErpViewController /
 * resources/views/operations/erp/index.blade.php) -- ported from the
 * source's own app/operations/erp/page.tsx. A read-only cross-module
 * aggregate: this file checks it aggregates real rows from the other four
 * modules correctly and degrades gracefully without their permissions,
 * not the other modules' own behaviour (each has its own test file).
 */
class ErpViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(LicensePlanSeeder::class);
        $this->seed(OrganisationAdministratorRoleSeeder::class);
    }

    /**
     * A licensed organisation -- required because this page reuses
     * App\Services\Administration\AdministrationSnapshotService for its
     * employee count whenever the actor holds employees:read, and that
     * service's own App\Support\Licensing\EntitlementGate::assert requires
     * a real ACTIVE license to exist for the organisation (matching
     * AdministrationViewTest's own makeLicensedOrganisation fixture).
     *
     * @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User}
     */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $subscription = Subscription::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider' => 'LOCAL_SYNTHETIC',
            'provider_reference' => 'synthetic-'.Str::random(12), 'status' => 'ACTIVE', 'activated_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth()->toDateString(), 'current_period_end' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $license = OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        LicenseUsage::create([
            'id' => (string) Str::uuid(), 'organisation_license_id' => $license->id, 'organisation_id' => $organisation->id,
            'metric_key' => 'USER_SEATS', 'period_key' => '2026-Q3', 'used_value' => 1, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@erpview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_erp_page_requires_authentication(): void
    {
        $this->get('/operations/erp')->assertRedirect('/login');
    }

    public function test_the_page_renders_module_tiles_aggregating_real_data(): void
    {
        $org = $this->makeOrganisation('VAT-ERP-0001');
        FixedAsset::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'asset_class' => 'MOVABLE',
            'asset_code' => 'VEH-ERP-1', 'category' => 'VEHICLE', 'description' => 'Fleet vehicle', 'location_or_address' => 'Depot',
            'acquisition_date' => '2022-01-01', 'acquisition_cost_cents' => 150_000_00, 'current_value_cents' => 120_000_00,
            'status' => 'ACTIVE', 'created_by' => $org['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/operations/erp');

        $response->assertOk()->assertViewIs('operations.erp.index');
        $response->assertSee('ERP Module');
        $response->assertSee('Human Resources');
        $response->assertSee('Fixed Assets');
        $response->assertSee('NAD 120,000.00');
    }

    public function test_a_role_without_employees_read_still_sees_the_page_with_that_tile_disabled(): void
    {
        $org = $this->makeOrganisation('VAT-ERP-0002');
        // TAXPAYER_STAFF holds expenses:read (this page's own gate), fixed-assets:read
        // and logistics:read, but not employees:read (that one stays TAXPAYER_OWNER/
        // ADMIN/ACCOUNTANT-and-above only) -- the one role that isolates this tile.
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff@erpview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($staff)->get('/operations/erp');

        $response->assertOk();
        $response->assertSee('No permission');
    }
}

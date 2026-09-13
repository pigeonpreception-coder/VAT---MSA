<?php

namespace Tests\Feature\Portal;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Portal\PortalService (ported from lib/portals.ts's
 * getAvailablePortals) -- found and closed out while porting
 * `getAdministrationSnapshot`/`searchWorkspace`, the last two functions
 * in control-plane-repository.ts, since this is a genuinely separate
 * file still squarely inside Phase 12's own "portals" scope.
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    public function test_a_taxpayer_owner_only_sees_the_capability_gated_portal_their_organisation_actually_holds(): void
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-PORTAL-0001', 'tin' => 'TIN-VAT-PORTAL-0001',
            'legal_name' => 'Portal Trading Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'portal-0001@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner-0001@test.test', 'password' => bcrypt('password'),
            'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/portals');
        $response->assertStatus(200);
        $keys = collect($response->json('portals'))->pluck('key')->all();

        // The org holds SELLER, not BUYER -- seller (capability-gated) is
        // visible, buyer is not, even though TAXPAYER_OWNER is listed
        // against both portals' own `roles`.
        $this->assertContains('seller', $keys);
        $this->assertNotContains('buyer', $keys);
        // TAXPAYER_OWNER is deliberately excluded from developer's own role
        // list (see Permissions::ROLE_PERMISSIONS' own comment on that role).
        $this->assertNotContains('developer', $keys);
        // Not listed in namra/namra-admin/super-admin's own roles at all.
        $this->assertNotContains('namra', $keys);
        $this->assertNotContains('namra-admin', $keys);
        $this->assertNotContains('super-admin', $keys);
    }

    public function test_namra_staff_sees_only_the_namra_portal(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Staff', 'email' => 'namra-staff-0002@test.test', 'password' => bcrypt('password'),
            'role' => 'NAMRA_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/portals');
        $response->assertStatus(200);
        $keys = collect($response->json('portals'))->pluck('key')->all();

        // NAMRA_STAFF (formerly PILOT_ADMIN, renamed and narrowed at the
        // user's own explicit request) is listed against only the NamRA
        // portal's own role list now -- no unconditional BUYER/SELLER
        // capability grant either (PortalService::capabilitySet no longer
        // special-cases this role).
        $this->assertSame(['namra'], $keys);
    }

    public function test_super_admin_sees_the_super_admin_and_developer_portals(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => 'super-admin-0003@test.test', 'password' => bcrypt('password'),
            'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/portals');
        $response->assertStatus(200);
        $keys = collect($response->json('portals'))->pluck('key')->all();
        sort($keys);

        $this->assertSame(['developer', 'super-admin'], $keys);
    }
}

<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use Database\Seeders\AuthorityGovernanceSeeder;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the NamRA Administration portal dashboard
 * (App\Http\Controllers\Portal\NamraAdminPortalController /
 * resources/views/portal/namra-admin.blade.php) -- ported from the
 * source's own app/portal/namra-admin/page.tsx, the sixth and final
 * portal dashboard. Reuses App\Services\AuthorityGovernance\
 * AuthorityGovernanceService::getSnapshot (already covered end to end by
 * tests/Feature/AuthorityGovernance/AuthorityGovernanceTest.php) and
 * App\Services\Identity\IdentityFoundationSnapshotService::getSnapshot
 * directly, so this file's own job is proving the portal-access gate
 * and the view's own rendering.
 */
class NamraAdminPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IdentityProviderSeeder::class);
        $this->seed(AuthorityGovernanceSeeder::class);
    }

    private function pilotAdmin(string $email = 'pilot@namraadminportal.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function makeAdministrator(User $user): void
    {
        DB::table('tax_authority_administrators')->insert([
            'id' => (string) Str::uuid(), 'tax_authority_id' => 'tax-authority-na-namra', 'user_id' => $user->id,
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'effective_to' => null,
            'appointed_by' => 'TEST_HARNESS', 'approval_reference' => 'TEST-REF-0001',
        ]);
    }

    public function test_the_namra_admin_portal_requires_authentication(): void
    {
        $this->get('/portal/namra-admin')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_namra_admin_portals_list_is_denied(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor@namraadminportal.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($auditor)->get('/portal/namra-admin')->assertForbidden();
    }

    public function test_an_administrator_with_no_governed_authority_scope_is_denied(): void
    {
        $admin = $this->pilotAdmin(); // authority-governance:read present, but no tax_authority_administrators row

        $this->actingAs($admin)->get('/portal/namra-admin')->assertForbidden();
    }

    public function test_the_namra_admin_portal_renders_units_federation_assignments_and_providers(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);
        DB::table('tax_authority_units')->insert([
            'id' => (string) Str::uuid(), 'tax_authority_id' => 'tax-authority-na-namra', 'parent_unit_id' => null,
            'code' => 'HQ', 'name' => 'Head Office', 'unit_type' => 'HEAD_OFFICE', 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $itasProviderId = DB::table('identity_providers')->where('provider_key', 'ITAS')->value('id');
        DB::table('tax_authority_federation_connections')->insert([
            'id' => (string) Str::uuid(), 'tax_authority_id' => 'tax-authority-na-namra', 'identity_provider_id' => $itasProviderId,
            'environment' => 'CONTRACT_PENDING', 'protocol' => 'UNCONFIRMED', 'status' => 'CONTRACT_PENDING',
            'requested_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/portal/namra-admin');

        $response->assertOk()->assertViewIs('portal.namra-admin');
        $response->assertSee('Authority provisioning, federation and activation control');
        $response->assertSee('Head Office');
        $response->assertSee('ITAS identity provider');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
        $response->assertSee('No implicit authority or financial access.');
        // The assigned-authority count is a metric only -- like the
        // source's own page, the authority's name is never rendered as
        // visible text anywhere on this screen.
        $this->assertTrue(collect($response->viewData('governance')['authorities'])->contains('id', 'tax-authority-na-namra'));
    }
}

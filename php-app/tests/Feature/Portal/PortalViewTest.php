<?php

namespace Tests\Feature\Portal;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the portal switchboard
 * (App\Http\Controllers\Portal\PortalViewController /
 * resources/views/portals/index.blade.php) -- ported from the source's
 * own app/portals/page.tsx. App\Services\Portal\PortalService and
 * App\Domain\Portal\PortalDefinitions are the actual role/capability
 * logic under test elsewhere (this migration's own JSON
 * App\Http\Controllers\Portal\PortalController shares the same service);
 * this file's own job is proving the view renders that data, is gated
 * correctly, and every "Open X" link resolves -- matching
 * InvoiceViewTest/AuditCaseViewTest's own division of labour.
 */
class PortalViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation} */
    private function makeTaxpayer(string $vatNumber, array $capabilities = []): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach ($capabilities as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }

        return compact('taxpayer', 'organisation');
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function namraStaff(string $email = 'namra-staff@portalview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Staff', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function superAdmin(string $email = 'super-admin@portalview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** INTERNAL_AUDITOR holds dashboard:read/audit:read but is on no portal's role list at all -- the genuine empty-state fixture. */
    private function internalAuditor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Internal Auditor', 'email' => 'auditor-'.Str::random(8).'@portalview.test',
            'password' => bcrypt('password'), 'role' => 'INTERNAL_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_portals_page_requires_authentication(): void
    {
        $this->get('/portals')->assertRedirect('/login');
    }

    public function test_a_taxpayer_owner_without_buyer_or_seller_capability_sees_the_empty_state(): void
    {
        $tp = $this->makeTaxpayer('VAT-PORTALVIEW-0001');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@portalview.test');

        $response = $this->actingAs($owner)->get('/portals');

        // TAXPAYER_OWNER is deliberately excluded from developer's own role
        // list now (see Permissions::ROLE_PERMISSIONS' own comment on that
        // role) -- with no BUYER/SELLER capability either, this owner is on
        // no portal's role list at all.
        $response->assertOk()->assertViewIs('portals.index');
        $response->assertSee('Choose an authorised VAT-MSA experience');
        $response->assertSee('No portal assignment.');
        $response->assertDontSee('Developer and sandbox');
        $this->assertSame([], $response->viewData('portals'));
    }

    public function test_a_taxpayer_owner_with_buyer_capability_sees_only_the_buyer_portal(): void
    {
        $tp = $this->makeTaxpayer('VAT-PORTALVIEW-0002', ['BUYER']);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-buyer@portalview.test');

        $response = $this->actingAs($owner)->get('/portals');

        $response->assertOk();
        $keys = collect($response->viewData('portals'))->pluck('key')->all();
        $this->assertContains('buyer', $keys);
        $this->assertNotContains('developer', $keys);
        $this->assertNotContains('seller', $keys);
        // Every "Open X" link resolves to the one real authenticated
        // landing page this port has, not a dead link to a portal
        // dashboard that doesn't exist yet.
        $response->assertSee(route('dashboard'), false);
    }

    public function test_namra_staff_sees_only_the_namra_portal(): void
    {
        $admin = $this->namraStaff();

        $response = $this->actingAs($admin)->get('/portals');

        $response->assertOk();
        $keys = collect($response->viewData('portals'))->pluck('key')->all();
        $this->assertSame(['namra'], $keys);
    }

    public function test_super_admin_sees_the_super_admin_and_developer_portals(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get('/portals');

        $response->assertOk();
        $keys = collect($response->viewData('portals'))->pluck('key')->all();
        sort($keys);
        $this->assertSame(['developer', 'super-admin'], $keys);
    }

    public function test_an_actor_on_no_portals_role_list_sees_the_empty_state(): void
    {
        $auditor = $this->internalAuditor();

        $response = $this->actingAs($auditor)->get('/portals');

        $response->assertOk();
        $response->assertSee('No portal assignment.');
        $this->assertSame([], $response->viewData('portals'));
    }
}

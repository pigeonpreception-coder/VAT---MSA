<?php

namespace Tests\Feature\Identity;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\OrganisationRole;
use App\Models\OrganisationRolePermission;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserCapabilityAssignment;
use App\Models\UserRoleAssignment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gap-finding pass (2026-09-24): app/api/v1/me/access/route.ts (lib/domain/
 * access.ts's getUserAccess) had no Laravel equivalent at all -- see
 * App\Http\Controllers\Identity\EffectiveAccessController's own doc
 * comment. Found by a systematic sweep of every app/api/v1/**\/route.ts
 * file against routes/web.php.
 */
class EffectiveAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/access')->assertStatus(401);
    }

    public function test_a_national_scope_actor_sees_no_organisation_and_static_permissions_only(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@effaccess.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/me/access');

        $response->assertOk();
        $response->assertJsonPath('user_id', $admin->id);
        $response->assertJsonPath('organisation_id', null);
        $response->assertJsonPath('taxpayer_id', null);
        $response->assertJsonPath('role', 'NAMRA_SYSTEM_SUPPORT');
        $response->assertJsonPath('is_national_scope', true);
        $response->assertJsonPath('is_development_identity', false);
        $response->assertJsonPath('capabilities', []);
        $this->assertContains('organisations:manage', $response->json('permissions'));
    }

    public function test_a_taxpayer_scoped_actor_sees_their_organisation_capabilities_and_dynamic_role_permissions(): void
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-EFFACCESS-0001', 'tin' => 'TIN-EFFACCESS-0001',
            'legal_name' => 'Effective Access Trading Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'effaccess@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@effaccess.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $owner->id,
            'role_code' => 'TAXPAYER_OWNER', 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $owner->id, 'created_at' => now(),
        ]);
        UserCapabilityAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $owner->id,
            'capability' => 'BUYER', 'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $owner->id,
        ]);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'name' => 'Extra Grant',
            'description' => 'A narrow custom role for testing.', 'version' => 1, 'branch_scope' => '[]',
            'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationRolePermission::create([
            'id' => (string) Str::uuid(), 'organisation_role_id' => $role->id, 'permission_code' => 'platform:manage',
            'record_scope' => 'ORGANISATION', 'effect' => 'ALLOW', 'created_at' => now(),
        ]);
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $owner->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $owner->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/me/access');

        $response->assertOk();
        $response->assertJsonPath('organisation_id', $organisation->id);
        $response->assertJsonPath('taxpayer_id', $taxpayer->id);
        $response->assertJsonPath('is_national_scope', false);
        $response->assertJsonPath('capabilities', ['BUYER']);
        // platform:manage is not a static TAXPAYER_OWNER grant -- present only via the dynamic role assignment.
        $this->assertContains('platform:manage', $response->json('permissions'));
        $this->assertContains('organisations:manage', $response->json('permissions'));
    }
}

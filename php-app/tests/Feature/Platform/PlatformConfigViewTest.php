<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Platform config console
 * (App\Http\Controllers\Platform\PlatformConfigViewController /
 * resources/views/platform/index.blade.php) -- reuses
 * App\Services\Platform\PlatformChangeService directly, already covered
 * end to end (every target-type shape check, the maker-checker self-
 * decision refusal, provisionStaff's identity-link creation) by
 * tests/Feature/Platform/PlatformChangeTest.php. This file's own job is
 * the access gate, the view's rendering, the real form submissions
 * reached through this UI, and the route-level password.confirm gate on
 * staff provisioning.
 */
class PlatformConfigViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IdentityProviderSeeder::class);
    }

    /** Holds platform:manage (and platform:read). */
    private function superAdmin(string $email = 'super@platformview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function infrastructureAdmin(string $email = 'infra@platformview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Infrastructure Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'INFRASTRUCTURE_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds platform:read but NOT platform:manage -- the read-only fixture. */
    private function pilotAdmin(string $email = 'pilot@platformview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither platform:read nor platform:manage -- the fully-denied fixture. */
    private function developerPartner(string $email = 'developer@platformview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function seedFeatureFlag(bool $enabled = false): string
    {
        $id = (string) Str::uuid();
        DB::table('feature_flags')->insert([
            'id' => $id, 'key' => 'test.flag.'.Str::random(8), 'name' => 'Test Flag', 'description' => 'A test feature flag.',
            'rollout_scope' => 'ALL', 'enabled' => $enabled, 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(),
        ]);

        return $id;
    }

    private function seedPlatformConfig(string $value = '10800'): string
    {
        $id = (string) Str::uuid();
        DB::table('platform_config')->insert([
            'id' => $id, 'key' => 'test.config.'.Str::random(8), 'category' => 'SECURITY', 'description' => 'A test config value.',
            'value' => $value, 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(),
        ]);

        return $id;
    }

    private function seedAccessPolicy(): string
    {
        $id = (string) Str::uuid();
        DB::table('access_policies')->insert([
            'id' => $id, 'code' => 'TEST_POLICY_'.Str::random(8), 'name' => 'Test Policy', 'policy_type' => 'RATE_LIMIT',
            'description' => 'A test access policy.', 'parameters' => json_encode(['max_attempts' => 5]), 'status' => 'ACTIVE',
            'version' => 1, 'created_at' => now(),
        ]);

        return $id;
    }

    public function test_the_platform_page_requires_authentication(): void
    {
        $this->get('/platform')->assertRedirect('/login');
    }

    public function test_a_role_without_platform_read_is_denied(): void
    {
        $this->actingAs($this->developerPartner())->get('/platform')->assertForbidden();
    }

    public function test_the_page_renders_for_a_read_only_actor_without_propose_actions(): void
    {
        $this->seedFeatureFlag(true);

        $response = $this->actingAs($this->pilotAdmin())->get('/platform');

        $response->assertOk()->assertViewIs('platform.index');
        $response->assertSee('Feature flags');
        $response->assertSee('test.flag.', false);
        $response->assertDontSee('Propose change');
        $response->assertSee('<caption class="visually-hidden">', false);
    }

    public function test_proposing_a_change_requires_platform_manage(): void
    {
        $flagId = $this->seedFeatureFlag(false);

        $this->actingAs($this->pilotAdmin())->post('/platform/change-requests', [
            'target_type' => 'FEATURE_FLAG', 'target_id' => $flagId, 'enabled' => '1', 'reason' => 'Attempted without permission.',
        ])->assertForbidden();
    }

    public function test_a_manager_can_propose_a_feature_flag_change(): void
    {
        $flagId = $this->seedFeatureFlag(false);
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/platform/change-requests', [
            'target_type' => 'FEATURE_FLAG', 'target_id' => $flagId, 'enabled' => '1', 'reason' => 'Enable for the pilot cohort.',
        ]);

        $response->assertRedirect('/platform');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('change_requests', ['target_id' => $flagId, 'status' => 'PENDING', 'requested_by' => $admin->id]);
        $this->assertDatabaseHas('feature_flags', ['id' => $flagId, 'enabled' => false]);
    }

    public function test_a_manager_can_propose_a_platform_config_value_change(): void
    {
        $configId = $this->seedPlatformConfig('7200');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/platform/change-requests', [
            'target_type' => 'PLATFORM_CONFIG', 'target_id' => $configId, 'value' => '14400', 'reason' => 'Widen the freshness window.',
        ]);

        $response->assertRedirect('/platform');
        $this->assertDatabaseHas('change_requests', ['target_id' => $configId, 'status' => 'PENDING']);
    }

    public function test_an_access_policy_change_with_invalid_json_parameters_is_refused(): void
    {
        $policyId = $this->seedAccessPolicy();
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/platform/change-requests', [
            'target_type' => 'ACCESS_POLICY', 'target_id' => $policyId, 'parameters' => 'not json', 'reason' => 'Tighten the policy.',
        ]);

        $response->assertRedirect('/platform');
        $response->assertSessionHasErrors('change');
        $this->assertDatabaseMissing('change_requests', ['target_id' => $policyId]);
    }

    public function test_a_reviewer_cannot_decide_their_own_change_request(): void
    {
        $flagId = $this->seedFeatureFlag(false);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/platform/change-requests', [
            'target_type' => 'FEATURE_FLAG', 'target_id' => $flagId, 'enabled' => '1', 'reason' => 'Enable for the pilot cohort.',
        ]);
        $changeId = DB::table('change_requests')->where('target_id', $flagId)->value('id');

        $response = $this->actingAs($admin)->post("/platform/change-requests/{$changeId}/decide", ['decision' => 'APPROVE', 'notes' => 'Approving my own request.']);

        $response->assertRedirect('/platform');
        $response->assertSessionHasErrors('decide');
        $this->assertDatabaseHas('change_requests', ['id' => $changeId, 'status' => 'PENDING']);
    }

    public function test_a_different_reviewer_can_approve_applying_the_proposed_change(): void
    {
        $flagId = $this->seedFeatureFlag(false);
        $requester = $this->superAdmin('requester@platformview.test');
        $approver = $this->infrastructureAdmin('approver@platformview.test');
        $this->actingAs($requester)->post('/platform/change-requests', [
            'target_type' => 'FEATURE_FLAG', 'target_id' => $flagId, 'enabled' => '1', 'reason' => 'Enable for the pilot cohort.',
        ]);
        $changeId = DB::table('change_requests')->where('target_id', $flagId)->value('id');

        $response = $this->actingAs($approver)->post("/platform/change-requests/{$changeId}/decide", ['decision' => 'APPROVE', 'notes' => 'Reviewed and approved.']);

        $response->assertRedirect('/platform');
        $this->assertDatabaseHas('change_requests', ['id' => $changeId, 'status' => 'APPLIED']);
        $this->assertDatabaseHas('feature_flags', ['id' => $flagId, 'enabled' => true, 'version' => 2]);
    }

    public function test_a_different_reviewer_can_reject_leaving_the_target_unchanged(): void
    {
        $flagId = $this->seedFeatureFlag(false);
        $requester = $this->superAdmin('requester2@platformview.test');
        $approver = $this->infrastructureAdmin('approver2@platformview.test');
        $this->actingAs($requester)->post('/platform/change-requests', [
            'target_type' => 'FEATURE_FLAG', 'target_id' => $flagId, 'enabled' => '1', 'reason' => 'Enable for the pilot cohort.',
        ]);
        $changeId = DB::table('change_requests')->where('target_id', $flagId)->value('id');

        $response = $this->actingAs($approver)->post("/platform/change-requests/{$changeId}/decide", ['decision' => 'REJECT', 'notes' => 'Not approved yet.']);

        $response->assertRedirect('/platform');
        $this->assertDatabaseHas('change_requests', ['id' => $changeId, 'status' => 'REJECTED']);
        $this->assertDatabaseHas('feature_flags', ['id' => $flagId, 'enabled' => false, 'version' => 1]);
    }

    public function test_provisioning_staff_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post('/platform/staff', [
            'external_user_id' => 'ext-staff-0001', 'email' => 'newstaff@platformview.test',
            'display_name' => 'New Staff Member', 'role' => 'SECURITY_ANALYST',
        ]);

        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('users', ['email' => 'newstaff@platformview.test']);
    }

    public function test_provisioning_staff_with_a_fresh_step_up_creates_the_account(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/platform/staff', [
                'external_user_id' => 'ext-staff-0002', 'email' => 'newstaff2@platformview.test',
                'display_name' => 'New Staff Member', 'role' => 'SECURITY_ANALYST',
            ]);

        $response->assertRedirect('/platform');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'newstaff2@platformview.test', 'role' => 'SECURITY_ANALYST']);
        $this->assertDatabaseHas('identity_links', ['subject' => 'ext-staff-0002']);
    }
}

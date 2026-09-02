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
 * Covers App\Services\Platform\PlatformChangeService (ported from
 * lib/data/platform-repository.ts's getPlatformConfig/
 * listPlatformChangeRequests/requestPlatformChange/decidePlatformChange/
 * provisionPlatformStaff) -- Module 8 Phase A, Phase 13's sixth and final
 * slice.
 */
class PlatformChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IdentityProviderSeeder::class);
    }

    /** Holds platform:manage (and platform:read). */
    private function superAdmin(string $email = 'super@platformchangetest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function infrastructureAdmin(string $email = 'infra@platformchangetest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Infrastructure Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'INFRASTRUCTURE_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds platform:read but NOT platform:manage -- the read-only fixture. */
    private function pilotAdmin(string $email = 'pilot@platformchangetest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither platform:read nor platform:manage -- the fully-denied fixture. */
    private function developerPartner(string $email = 'developer@platformchangetest.test'): User
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

    /** @param array<string, mixed> $parameters */
    private function seedAccessPolicy(array $parameters = ['max_attempts' => 5]): string
    {
        $id = (string) Str::uuid();
        DB::table('access_policies')->insert([
            'id' => $id, 'code' => 'TEST_POLICY_'.Str::random(8), 'name' => 'Test Policy', 'policy_type' => 'RATE_LIMIT',
            'description' => 'A test access policy.', 'parameters' => json_encode($parameters), 'status' => 'ACTIVE',
            'version' => 1, 'created_at' => now(),
        ]);

        return $id;
    }

    /** @return array{schema_version: string} */
    private function requestChangeBody(string $targetType, string $targetId, array $proposedValue, string $reason = 'A real reason for this proposed change.'): array
    {
        return ['schema_version' => '1.0.0', 'target_type' => $targetType, 'target_id' => $targetId, 'proposed_value' => $proposedValue, 'reason' => $reason];
    }

    private function decisionBody(string $decision, string $notes = 'A real rationale for this decision.'): array
    {
        return ['schema_version' => '1.0.0', 'decision' => $decision, 'notes' => $notes];
    }

    public function test_getting_platform_config_requires_the_platform_read_permission_and_returns_only_active_rows(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(true);
        $configId = $this->seedPlatformConfig('7200');
        $policyId = $this->seedAccessPolicy(['max_attempts' => 3]);
        $retiredFlagId = (string) Str::uuid();
        DB::table('feature_flags')->insert([
            'id' => $retiredFlagId, 'key' => 'retired.flag', 'name' => 'Retired', 'description' => 'Retired flag.',
            'rollout_scope' => 'ALL', 'enabled' => false, 'status' => 'RETIRED', 'version' => 1, 'created_at' => now(),
        ]);

        $this->actingAs($this->developerPartner())->getJson('/api/v1/platform/config')->assertStatus(403);
        $this->actingAs($this->pilotAdmin())->getJson('/api/v1/platform/config')->assertStatus(200);

        $response = $this->actingAs($admin)->getJson('/api/v1/platform/config');
        $response->assertStatus(200);
        $flags = collect($response->json('feature_flags'));
        $this->assertTrue($flags->contains('id', $flagId));
        $this->assertFalse($flags->contains('id', $retiredFlagId));
        $this->assertTrue(collect($response->json('platform_config'))->contains(fn ($c) => $c['id'] === $configId && $c['value'] === '7200'));
        $this->assertTrue(collect($response->json('access_policies'))->contains(fn ($p) => $p['id'] === $policyId && $p['parameters'] === ['max_attempts' => 3]));
    }

    public function test_listing_change_requests_requires_the_platform_read_permission_and_is_filterable_by_status(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);
        $requester = $this->infrastructureAdmin();
        $requestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests',
            $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-list-0001'])
            ->json('change_request.id');

        $this->actingAs($this->developerPartner())->getJson('/api/v1/platform/change-requests')->assertStatus(403);
        // pilotAdmin holds platform:read, so listing itself succeeds; only requestChange (platform:manage) is denied for it.
        $this->actingAs($this->pilotAdmin())->getJson('/api/v1/platform/change-requests')->assertStatus(200);

        $pending = $this->actingAs($admin)->getJson('/api/v1/platform/change-requests?status=PENDING');
        $pending->assertStatus(200);
        $this->assertTrue(collect($pending->json('change_requests'))->contains('id', $requestId));

        $applied = $this->actingAs($admin)->getJson('/api/v1/platform/change-requests?status=APPLIED');
        $applied->assertStatus(200);
        $this->assertFalse(collect($applied->json('change_requests'))->contains('id', $requestId));
    }

    public function test_requesting_a_change_requires_the_platform_manage_permission(): void
    {
        $flagId = $this->seedFeatureFlag(false);
        $readOnly = $this->pilotAdmin();

        $this->actingAs($readOnly)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-manage-0001'])
            ->assertStatus(403);
    }

    public function test_requesting_a_change_refuses_an_unknown_target_and_an_invalid_proposed_value_shape(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);

        $this->actingAs($admin)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', (string) Str::uuid(), ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-unknown-0001'])
            ->assertStatus(404);

        $this->actingAs($admin)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => 'yes']), ['Idempotency-Key' => 'test-idem-pc-badshape-0001'])
            ->assertStatus(422);
    }

    public function test_requesting_a_change_snapshots_the_previous_value_and_is_idempotent(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);
        $body = $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]);

        $first = $this->actingAs($admin)->postJson('/api/v1/platform/change-requests', $body, ['Idempotency-Key' => 'test-idem-pc-snapshot-0001']);
        $first->assertStatus(201);
        $this->assertSame('PENDING', $first->json('change_request.status'));
        $this->assertSame(['enabled' => false], json_decode($first->json('change_request.previous_value'), true));
        $this->assertSame(['enabled' => true], json_decode($first->json('change_request.proposed_value'), true));

        $second = $this->actingAs($admin)->postJson('/api/v1/platform/change-requests', $body, ['Idempotency-Key' => 'test-idem-pc-snapshot-0001']);
        $second->assertStatus(201);
        $this->assertSame($first->json('change_request.id'), $second->json('change_request.id'));
        $this->assertDatabaseCount('change_requests', 1);
    }

    public function test_deciding_a_change_requires_the_platform_manage_permission(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);
        $changeRequestId = $this->actingAs($admin)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-decide-setup-0001'])
            ->json('change_request.id');
        $readOnly = $this->pilotAdmin();

        $this->actingAs($readOnly)->postJson("/api/v1/platform/change-requests/{$changeRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-decide-denied-0001'])
            ->assertStatus(403);
    }

    public function test_deciding_an_unknown_or_already_decided_change_request_and_refuses_self_decision(): void
    {
        $admin = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);

        $this->actingAs($admin)->postJson('/api/v1/platform/change-requests/'.((string) Str::uuid()).'/decision', $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-decide-unknown-0001'])
            ->assertStatus(404);

        $requester = $this->infrastructureAdmin();
        $changeRequestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-decide-self-setup-0001'])
            ->json('change_request.id');

        $this->actingAs($requester)->postJson("/api/v1/platform/change-requests/{$changeRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-decide-self-0001'])
            ->assertStatus(403);

        $this->actingAs($admin)->postJson("/api/v1/platform/change-requests/{$changeRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-decide-applied-0001'])
            ->assertStatus(200);
        $this->actingAs($admin)->postJson("/api/v1/platform/change-requests/{$changeRequestId}/decision", $this->decisionBody('REJECT'), ['Idempotency-Key' => 'test-idem-pc-decide-alreadydone-0001'])
            ->assertStatus(409);
    }

    public function test_approving_a_change_applies_it_and_bumps_the_version_for_each_target_type(): void
    {
        $requester = $this->infrastructureAdmin();
        $approver = $this->superAdmin();

        $flagId = $this->seedFeatureFlag(false);
        $flagRequestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-apply-flag-req-0001'])
            ->json('change_request.id');
        $this->actingAs($approver)->postJson("/api/v1/platform/change-requests/{$flagRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-apply-flag-dec-0001'])
            ->assertStatus(200);
        $flag = DB::table('feature_flags')->where('id', $flagId)->first();
        $this->assertSame(1, (int) $flag->enabled);
        $this->assertSame(2, (int) $flag->version);

        $configId = $this->seedPlatformConfig('10800');
        $configRequestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('PLATFORM_CONFIG', $configId, ['value' => '21600']), ['Idempotency-Key' => 'test-idem-pc-apply-config-req-0001'])
            ->json('change_request.id');
        $this->actingAs($approver)->postJson("/api/v1/platform/change-requests/{$configRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-apply-config-dec-0001'])
            ->assertStatus(200);
        $config = DB::table('platform_config')->where('id', $configId)->first();
        $this->assertSame('21600', $config->value);
        $this->assertSame(2, (int) $config->version);

        $policyId = $this->seedAccessPolicy(['max_attempts' => 5]);
        $policyRequestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('ACCESS_POLICY', $policyId, ['parameters' => ['max_attempts' => 3]]), ['Idempotency-Key' => 'test-idem-pc-apply-policy-req-0001'])
            ->json('change_request.id');
        $this->actingAs($approver)->postJson("/api/v1/platform/change-requests/{$policyRequestId}/decision", $this->decisionBody('APPROVE'), ['Idempotency-Key' => 'test-idem-pc-apply-policy-dec-0001'])
            ->assertStatus(200);
        $policy = DB::table('access_policies')->where('id', $policyId)->first();
        $this->assertSame(['max_attempts' => 3], json_decode($policy->parameters, true));
        $this->assertSame(2, (int) $policy->version);
    }

    public function test_rejecting_a_change_leaves_the_target_untouched(): void
    {
        $requester = $this->infrastructureAdmin();
        $approver = $this->superAdmin();
        $flagId = $this->seedFeatureFlag(false);
        $requestId = $this->actingAs($requester)->postJson('/api/v1/platform/change-requests', $this->requestChangeBody('FEATURE_FLAG', $flagId, ['enabled' => true]), ['Idempotency-Key' => 'test-idem-pc-reject-req-0001'])
            ->json('change_request.id');

        $rejected = $this->actingAs($approver)->postJson("/api/v1/platform/change-requests/{$requestId}/decision", $this->decisionBody('REJECT'), ['Idempotency-Key' => 'test-idem-pc-reject-dec-0001']);
        $rejected->assertStatus(200);
        $this->assertSame('REJECTED', $rejected->json('change_request.status'));

        $flag = DB::table('feature_flags')->where('id', $flagId)->first();
        $this->assertSame(0, (int) $flag->enabled);
        $this->assertSame(1, (int) $flag->version);
    }

    /** @return array<string, mixed> */
    private function staffBody(string $externalUserId, string $email, string $role = 'NAMRA_AUDITOR'): array
    {
        return ['schema_version' => '1.0.0', 'external_user_id' => $externalUserId, 'email' => $email, 'display_name' => 'New Platform Staff', 'role' => $role];
    }

    public function test_provisioning_staff_requires_the_platform_manage_permission_and_a_fresh_step_up(): void
    {
        $readOnly = $this->pilotAdmin();
        $body = $this->staffBody('ext-staff-0001', 'staff-denied@platformchangetest.test');

        $this->actingAs($readOnly)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $body, ['Idempotency-Key' => 'test-idem-pc-staff-perm-0001'])
            ->assertStatus(403);

        // Explicitly stale rather than absent -- the test session store can
        // otherwise carry the earlier call's own fresh confirmation forward.
        $admin = $this->superAdmin();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 20_000])
            ->postJson('/api/v1/platform/staff', $body, ['Idempotency-Key' => 'test-idem-pc-staff-stepup-0001'])
            ->assertStatus(423);
    }

    public function test_provisioning_staff_refuses_a_duplicate_identity_or_email_and_an_unprovisionable_role(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $this->staffBody('ext-dup-0001', 'dup@platformchangetest.test'), ['Idempotency-Key' => 'test-idem-pc-staff-first-0001'])
            ->assertStatus(201);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $this->staffBody('ext-dup-0002', 'dup@platformchangetest.test'), ['Idempotency-Key' => 'test-idem-pc-staff-dupemail-0001'])
            ->assertStatus(409);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $this->staffBody('ext-dup-0001', 'other@platformchangetest.test'), ['Idempotency-Key' => 'test-idem-pc-staff-dupid-0001'])
            ->assertStatus(409);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $this->staffBody('ext-badrole-0001', 'badrole@platformchangetest.test', 'TAXPAYER_OWNER'), ['Idempotency-Key' => 'test-idem-pc-staff-badrole-0001'])
            ->assertStatus(422);
    }

    public function test_provisioning_staff_succeeds_creates_no_organisation_link_and_is_idempotent(): void
    {
        $admin = $this->superAdmin();
        $body = $this->staffBody('ext-success-0001', 'success@platformchangetest.test');

        $first = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $body, ['Idempotency-Key' => 'test-idem-pc-staff-success-0001']);
        $first->assertStatus(201);
        $staffId = $first->json('staff.id');
        $this->assertSame('NAMRA_AUDITOR', $first->json('staff.role'));
        $this->assertSame('ACTIVE', $first->json('staff.status'));

        $created = DB::table('users')->where('id', $staffId)->first();
        $this->assertNull($created->taxpayer_id);
        $this->assertSame('ext-success-0001', $created->external_user_id);
        $this->assertDatabaseCount('identity_links', 1);
        $link = DB::table('identity_links')->where('user_id', $staffId)->first();
        $this->assertSame('PLATFORM_AUTHENTICATED', $link->assurance_level);

        $second = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/platform/staff', $body, ['Idempotency-Key' => 'test-idem-pc-staff-success-0001']);
        $second->assertStatus(201);
        $this->assertSame($staffId, $second->json('staff.id'));
        $this->assertDatabaseCount('identity_links', 1);
    }
}

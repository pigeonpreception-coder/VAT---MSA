<?php

namespace Tests\Feature\AuthorityGovernance;

use App\Models\User;
use Database\Seeders\AuthorityGovernanceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\AuthorityGovernance\AuthorityGovernanceService
 * (ported from lib/data/authority-governance-repository.ts's
 * getAuthorityGovernanceSnapshot/createAuthorityOnboardingCase/
 * decideAuthorityOnboardingCase -- the backend the NamRA Administration
 * portal needed, a genuinely new module for this migration). Exercises
 * the JSON command surface
 * (App\Http\Controllers\AuthorityGovernance\AuthorityGovernanceController)
 * end to end; the portal view's own test
 * (tests/Feature/Portal/NamraAdminPortalTest.php) covers rendering only.
 */
class AuthorityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AuthorityGovernanceSeeder::class);
    }

    private function pilotAdmin(string $email = 'pilot@authoritygov.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSystemAdmin(string $email = 'namra-admin@authoritygov.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA System Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
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

    private function makeCurrentAccessReview(): void
    {
        DB::table('tax_authority_access_reviews')->insert([
            'id' => (string) Str::uuid(), 'tax_authority_id' => 'tax-authority-na-namra', 'review_type' => 'QUARTERLY',
            'period_start' => now()->startOfQuarter()->toDateString(), 'due_at' => now()->addMonth(), 'status' => 'OPEN',
            'owner_id' => $this->pilotAdmin('review-owner@authoritygov.test')->id, 'completed_by' => null, 'completed_at' => null, 'created_at' => now(),
        ]);
    }

    private function onboardingPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'tax_authority_id' => 'tax-authority-na-namra', 'target_environment' => 'LOCAL_STAGING',
            'purpose' => 'Validate the authority hierarchy and independent approval workflow.',
        ], $overrides);
    }

    public function test_the_snapshot_requires_authority_governance_read(): void
    {
        $tp = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner@authoritygov.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($tp)->getJson('/api/v1/tax-authority-onboarding-cases')->assertStatus(403);
    }

    public function test_the_snapshot_denies_an_actor_with_no_governed_authority_scope(): void
    {
        $admin = $this->pilotAdmin();

        $this->actingAs($admin)->getJson('/api/v1/tax-authority-onboarding-cases')->assertStatus(403);
    }

    public function test_the_snapshot_returns_the_actors_administered_authorities_and_reference_data(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);

        $response = $this->actingAs($admin)->getJson('/api/v1/tax-authority-onboarding-cases');

        $response->assertStatus(200);
        $body = $response->json('governance');
        $this->assertTrue(collect($body['authorities'])->contains('id', 'tax-authority-na-namra'));
        $this->assertGreaterThanOrEqual(9, count($body['roles'])); // the fixed role-definition catalogue
        $this->assertFalse($body['productionActivationEnabled']);
    }

    public function test_an_onboarding_case_can_be_created_for_local_staging(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-create-0001']);

        $response->assertStatus(201)->assertJsonPath('onboarding_case.status', 'SUBMITTED')->assertJsonPath('production_activation_effect', false);
        $this->assertDatabaseHas('tax_authority_governance_events', ['event_type' => 'TaxAuthorityOnboardingRequested']);
    }

    public function test_a_production_onboarding_case_is_created_blocked(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(['target_environment' => 'PRODUCTION']), ['Idempotency-Key' => 'test-idem-agov-create-0002']);

        $response->assertStatus(201)->assertJsonPath('onboarding_case.status', 'BLOCKED_EXTERNAL');
    }

    public function test_creating_a_case_without_administrator_scope_is_denied(): void
    {
        $admin = $this->pilotAdmin(); // not registered as an administrator

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-create-0003']);

        $response->assertStatus(403);
    }

    public function test_a_duplicate_open_case_for_the_same_authority_and_environment_is_a_conflict(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-dup-0001'])
            ->assertStatus(201);

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-dup-0002']);

        $response->assertStatus(409);
    }

    public function test_creating_a_case_without_step_up_confirmation_is_denied(): void
    {
        $admin = $this->pilotAdmin();
        $this->makeAdministrator($admin);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-nostepup-0001']);

        $response->assertStatus(423);
    }

    public function test_the_requester_cannot_decide_their_own_case(): void
    {
        $maker = $this->namraSystemAdmin();
        $this->makeAdministrator($maker);
        $this->makeCurrentAccessReview();
        $caseId = $this->actingAs($maker)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-self-0001'])
            ->assertStatus(201)->json('onboarding_case.id');

        $response = $this->actingAs($maker)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/tax-authority-onboarding-cases/{$caseId}/decisions", [
                'schema_version' => '1.0.0', 'decision' => 'APPROVE_LOCAL_STAGING', 'reason' => 'Reviewed the submitted evidence bundle.',
            ], ['Idempotency-Key' => 'test-idem-agov-self-0002']);

        $response->assertStatus(403);
    }

    public function test_a_distinct_reviewer_can_approve_local_staging(): void
    {
        $maker = $this->namraSystemAdmin('maker@authoritygov.test');
        $reviewer = $this->pilotAdmin('reviewer@authoritygov.test');
        $this->makeAdministrator($maker);
        $this->makeAdministrator($reviewer);
        $this->makeCurrentAccessReview();
        $caseId = $this->actingAs($maker)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-approve-0001'])
            ->assertStatus(201)->json('onboarding_case.id');

        $response = $this->actingAs($reviewer)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/tax-authority-onboarding-cases/{$caseId}/decisions", [
                'schema_version' => '1.0.0', 'decision' => 'APPROVE_LOCAL_STAGING', 'reason' => 'Reviewed the submitted evidence bundle.',
            ], ['Idempotency-Key' => 'test-idem-agov-approve-0002']);

        $response->assertStatus(200)->assertJsonPath('onboarding_case.status', 'LOCAL_STAGING_READY');
        $this->assertDatabaseHas('tax_authority_onboarding_decisions', ['onboarding_case_id' => $caseId, 'decision' => 'APPROVE', 'decision_type' => 'LOCAL_STAGING_APPROVAL']);
    }

    public function test_a_decision_without_a_current_access_review_is_denied(): void
    {
        $maker = $this->namraSystemAdmin('maker2@authoritygov.test');
        $reviewer = $this->pilotAdmin('reviewer2@authoritygov.test');
        $this->makeAdministrator($maker);
        $this->makeAdministrator($reviewer);
        $this->makeCurrentAccessReview();
        $caseId = $this->actingAs($maker)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/tax-authority-onboarding-cases', $this->onboardingPayload(), ['Idempotency-Key' => 'test-idem-agov-noreview-0001'])
            ->assertStatus(201)->json('onboarding_case.id');
        DB::table('tax_authority_access_reviews')->where('tax_authority_id', 'tax-authority-na-namra')->delete();

        $response = $this->actingAs($reviewer)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/tax-authority-onboarding-cases/{$caseId}/decisions", [
                'schema_version' => '1.0.0', 'decision' => 'APPROVE_LOCAL_STAGING', 'reason' => 'Reviewed the submitted evidence bundle.',
            ], ['Idempotency-Key' => 'test-idem-agov-noreview-0002']);

        $response->assertStatus(403);
    }
}

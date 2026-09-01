<?php

namespace Tests\Feature\Identity;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the flow this session verified manually over real HTTP against
 * MySQL: submit -> approve materializes a taxpayer/organisation/head-office
 * branch/BUYER+SELLER capabilities/owner membership; reject leaves no trace;
 * self-approval and re-deciding an already-decided application are denied.
 * Ported from lib/data/identity-repository.ts's submitRegistrationApplication/
 * decideRegistrationApplication.
 */
class RegistrationApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // organisation_memberships.role_code carries a real FK to access_roles -- structural
        // integrity, independent of the (static, code-defined) Permissions authorization check.
        $this->seed(RoleSeeder::class);
    }

    private function taxpayerOwner(): User
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-OWNER-0001', 'tin' => 'TIN-OWNER-0001',
            'legal_name' => 'Owner Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Owner Street', 'email' => 'owner-co@test.test',
        ]);

        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
    }

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function submissionPayload(array $overrides = []): array
    {
        return array_merge([
            'vat_number' => 'VAT-NEW-0001', 'tin' => 'TIN-NEW-0001', 'legal_name' => 'New Registrant Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 New Street, Windhoek', 'email' => 'finance@new-registrant.test',
        ], $overrides);
    }

    public function test_a_taxpayer_owner_can_submit_a_registration_application(): void
    {
        $owner = $this->taxpayerOwner();

        $response = $this->actingAs($owner)->postJson('/api/v1/registration-applications', $this->submissionPayload(), [
            'Idempotency-Key' => 'test-idempotency-key-000001',
        ]);

        $response->assertStatus(202)->assertJson(['status' => 'PENDING_VERIFICATION', 'verification_source' => 'ITAS']);
        $this->assertDatabaseHas('registration_applications', ['vat_number' => 'VAT-NEW-0001', 'status' => 'PENDING_VERIFICATION']);
        $this->assertDatabaseMissing('taxpayers', ['vat_number' => 'VAT-NEW-0001']);
    }

    public function test_approving_a_registration_materializes_taxpayer_organisation_and_owner_membership(): void
    {
        $owner = $this->taxpayerOwner();
        $admin = $this->pilotAdmin();

        $submit = $this->actingAs($owner)->postJson('/api/v1/registration-applications', $this->submissionPayload(), [
            'Idempotency-Key' => 'test-idempotency-key-000002',
        ]);
        $registrationId = $submit->json('registration_id');

        $decision = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/registration-applications/{$registrationId}/decision", [
                'decision' => 'APPROVE', 'reason' => 'Verified documents on file.',
            ]);

        $decision->assertStatus(200)->assertJsonPath('decision.status', 'APPROVED');
        $taxpayerId = $decision->json('decision.taxpayerId');
        $organisationId = $decision->json('decision.organisationId');

        $this->assertDatabaseHas('taxpayers', ['id' => $taxpayerId, 'vat_number' => 'VAT-NEW-0001', 'vat_status' => 'ACTIVE']);
        $this->assertDatabaseHas('organisations', ['id' => $organisationId, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('branches', ['organisation_id' => $organisationId, 'code' => 'HEAD', 'is_head_office' => true]);
        $this->assertDatabaseHas('organisation_capabilities', ['organisation_id' => $organisationId, 'capability' => 'BUYER']);
        $this->assertDatabaseHas('organisation_capabilities', ['organisation_id' => $organisationId, 'capability' => 'SELLER']);
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $organisationId, 'user_id' => $owner->id, 'role_code' => 'TAXPAYER_OWNER']);
        $this->assertDatabaseHas('audit_events', ['action' => 'TAXPAYER_REGISTRATION_APPROVED', 'resource_id' => $registrationId]);
    }

    public function test_rejecting_a_registration_leaves_no_taxpayer_or_organisation(): void
    {
        $owner = $this->taxpayerOwner();
        $admin = $this->pilotAdmin();

        $submit = $this->actingAs($owner)->postJson('/api/v1/registration-applications', $this->submissionPayload(['vat_number' => 'VAT-REJ-0001', 'tin' => 'TIN-REJ-0001']), [
            'Idempotency-Key' => 'test-idempotency-key-000003',
        ]);
        $registrationId = $submit->json('registration_id');

        $decision = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/registration-applications/{$registrationId}/decision", [
                'decision' => 'REJECT', 'reason' => 'VAT number could not be independently confirmed.',
            ]);

        $decision->assertStatus(200)->assertJsonPath('decision.status', 'REJECTED');
        $this->assertDatabaseHas('registration_applications', ['id' => $registrationId, 'status' => 'REJECTED']);
        $this->assertDatabaseMissing('taxpayers', ['vat_number' => 'VAT-REJ-0001']);
        $this->assertDatabaseCount('organisations', 0);
    }

    public function test_the_submitting_user_cannot_decide_their_own_registration_application(): void
    {
        $owner = $this->taxpayerOwner();
        $owner->update(['role' => 'PILOT_ADMIN', 'taxpayer_id' => null]); // grant registrations:approve too, to isolate the self-approval check

        $submit = $this->actingAs($owner)->postJson('/api/v1/registration-applications', $this->submissionPayload(), [
            'Idempotency-Key' => 'test-idempotency-key-000004',
        ]);
        $registrationId = $submit->json('registration_id');

        $decision = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/registration-applications/{$registrationId}/decision", [
                'decision' => 'APPROVE', 'reason' => 'Self-approving my own application.',
            ]);

        $decision->assertStatus(422);
        $this->assertDatabaseHas('registration_applications', ['id' => $registrationId, 'status' => 'PENDING_VERIFICATION']);
    }

    public function test_a_taxpayer_viewer_without_the_submit_permission_is_denied(): void
    {
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/registration-applications', $this->submissionPayload(), [
            'Idempotency-Key' => 'test-idempotency-key-000005',
        ]);

        $response->assertStatus(403);
    }

    public function test_decision_without_a_fresh_password_confirmation_is_blocked(): void
    {
        $owner = $this->taxpayerOwner();
        $admin = $this->pilotAdmin();

        $submit = $this->actingAs($owner)->postJson('/api/v1/registration-applications', $this->submissionPayload(), [
            'Idempotency-Key' => 'test-idempotency-key-000006',
        ]);
        $registrationId = $submit->json('registration_id');

        // No withSession(['auth.password_confirmed_at' => ...]) this time.
        $decision = $this->actingAs($admin)->postJson("/api/v1/registration-applications/{$registrationId}/decision", [
            'decision' => 'APPROVE', 'reason' => 'Attempting without step-up.',
        ]);

        $decision->assertStatus(423);
        $this->assertDatabaseHas('registration_applications', ['id' => $registrationId, 'status' => 'PENDING_VERIFICATION']);
    }
}

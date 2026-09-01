<?php

namespace Tests\Feature\AccessGovernance;

use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\OrganisationMembership;
use App\Models\OrganisationRole;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserCapabilityAssignment;
use App\Models\UserRoleAssignment;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\AccessGovernance\AccessGovernanceService (ported
 * from lib/data/control-plane-repository.ts's requestRoleAccess/
 * decideAccessRequest/certifyQuarterlyAccess/revokeAccessGrant/
 * offboardUser) -- Phase 12 slice 4, the rest of Access governance.
 */
class AccessGovernanceTest extends TestCase
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

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeLicensedOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $subscription = Subscription::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider' => 'LOCAL_SYNTHETIC',
            'provider_reference' => 'synthetic-'.Str::random(12), 'status' => 'ACTIVE', 'activated_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth()->toDateString(), 'current_period_end' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeMember(Taxpayer $taxpayer, Organisation $organisation, string $email, User $assignedBy): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email, 'password' => bcrypt('password'),
            'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $user->id,
            'role_code' => 'TAXPAYER_STAFF', 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $assignedBy->id, 'created_at' => now(),
        ]);

        return $user;
    }

    private function makeOrganisationRole(Organisation $organisation, User $createdBy, string $name = 'Reconciliation Reviewer'): OrganisationRole
    {
        return OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'name' => $name,
            'description' => 'A narrow custom role for testing.', 'version' => 1, 'branch_scope' => '[]',
            'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $createdBy->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function openReview(User $actor): void
    {
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')
            ->assertStatus(201);
    }

    public function test_requesting_role_access_validates_membership_and_role_then_creates_a_pending_request(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ACCGOV-0001');
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0001@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);

        $shortJustification = $this->actingAs($ctx['owner'])->postJson('/api/v1/access-requests', [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'too short',
        ]);
        $shortJustification->assertStatus(422)->assertJsonPath('code', 'JUSTIFICATION_REQUIRED');

        $badRole = $this->actingAs($ctx['owner'])->postJson('/api/v1/access-requests', [
            'subject_user_id' => $staff->id, 'role_id' => (string) Str::uuid(), 'justification' => 'A genuinely valid justification string.',
        ]);
        $badRole->assertStatus(422)->assertJsonPath('code', 'ACCESS_REFERENCE_INVALID');

        $ok = $this->actingAs($ctx['owner'])->postJson('/api/v1/access-requests', [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);
        $ok->assertStatus(201)->assertJsonPath('request.status', 'PENDING_MANAGER');
        $this->assertDatabaseHas('access_requests', ['id' => $ok->json('request.id'), 'status' => 'PENDING_MANAGER']);
    }

    public function test_deciding_an_access_request_denies_self_approval_and_creates_a_role_assignment_on_approve(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ACCGOV-0002');
        $this->openReview($ctx['owner']);
        // The subject is given TAXPAYER_ADMIN (access-governance:manage)
        // specifically so the SELF_APPROVAL_DENIED check on the subject
        // path can actually be reached -- a TAXPAYER_STAFF subject would
        // be refused earlier still, by the Gate itself (403), never
        // reaching the self-check this assertion targets.
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0002@test.test', $ctx['owner']);
        $staff->update(['role' => 'TAXPAYER_ADMIN']);
        $approver = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'approver-0002@test.test', $ctx['owner']);
        $approver->update(['role' => 'TAXPAYER_ADMIN']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);

        $requested = $this->actingAs($ctx['owner'])->postJson('/api/v1/access-requests', [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);
        $requestId = $requested->json('request.id');

        // The requester (owner) cannot decide their own request.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-requests/{$requestId}/decision", ['decision' => 'approve', 'reason' => 'Self decision attempt.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_APPROVAL_DENIED');

        // Nor can the subject themselves.
        $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-requests/{$requestId}/decision", ['decision' => 'approve', 'reason' => 'Subject decision attempt.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_APPROVAL_DENIED');

        $decided = $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-requests/{$requestId}/decision", ['decision' => 'approve', 'reason' => 'Justified and verified.']);
        $decided->assertStatus(200)->assertJsonPath('decision.status', 'APPROVED');
        $this->assertDatabaseHas('user_role_assignments', [
            'organisation_id' => $ctx['organisation']->id, 'user_id' => $staff->id, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
        ]);

        // Already decided -- a second decision is a conflict.
        $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-requests/{$requestId}/decision", ['decision' => 'reject', 'reason' => 'Too late, already decided.'])
            ->assertStatus(409);
    }

    public function test_certifying_quarterly_access_retains_or_revokes_and_completes_the_review_once_every_member_is_certified(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ACCGOV-0003');
        $subjectA = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-a-0003@test.test', $ctx['owner']);
        $subjectB = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-b-0003@test.test', $ctx['owner']);
        UserCapabilityAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subjectB->id,
            'capability' => 'BUYER', 'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id,
        ]);

        $review = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/access-reviews');
        $review->assertStatus(201);
        $reviewId = $review->json('review.id');

        // Neither owner (not a member) nor the review's own non-member state blocks a genuine bad disposition.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-reviews/{$reviewId}/certifications", ['subject_user_id' => $subjectA->id, 'disposition' => 'MAYBE'])
            ->assertStatus(422)->assertJsonPath('code', 'DISPOSITION_INVALID');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-reviews/{$reviewId}/certifications", ['subject_user_id' => $ctx['owner']->id, 'disposition' => 'RETAIN'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_CERTIFICATION_DENIED');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-reviews/{$reviewId}/certifications", ['subject_user_id' => (string) Str::uuid(), 'disposition' => 'RETAIN'])
            ->assertStatus(422)->assertJsonPath('code', 'SUBJECT_NOT_ACTIVE');

        $first = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-reviews/{$reviewId}/certifications", ['subject_user_id' => $subjectA->id, 'disposition' => 'RETAIN']);
        $first->assertStatus(201)->assertJsonPath('certification.disposition', 'RETAIN');
        $this->assertDatabaseHas('access_reviews', ['id' => $reviewId, 'status' => 'OPEN']);

        $second = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/access-reviews/{$reviewId}/certifications", ['subject_user_id' => $subjectB->id, 'disposition' => 'REVOKE']);
        $second->assertStatus(201)->assertJsonPath('certification.disposition', 'REVOKE');
        // Every active member now certified -- the review auto-completes.
        $this->assertDatabaseHas('access_reviews', ['id' => $reviewId, 'status' => 'COMPLETED']);
        // REVOKE cascades: membership and the capability grant both end.
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subjectB->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('user_capability_assignments', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subjectB->id, 'status' => 'REVOKED']);

        // A review that is not open (bogus id here) is a conflict, not a validation error.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews/'.((string) Str::uuid()).'/certifications', ['subject_user_id' => $subjectA->id, 'disposition' => 'RETAIN'])
            ->assertStatus(409);
    }

    public function test_revoking_a_single_access_grant_is_idempotent_and_denies_self_revocation(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ACCGOV-0004');
        $this->openReview($ctx['owner']);
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0004@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        $assignment = UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-grants/revocation', ['grant_type' => 'ROLE', 'grant_id' => (string) Str::uuid(), 'reason' => 'Not found test.'])
            ->assertStatus(422)->assertJsonPath('code', 'GRANT_NOT_FOUND');

        $ownGrant = UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $ctx['owner']->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-grants/revocation', ['grant_type' => 'ROLE', 'grant_id' => $ownGrant->id, 'reason' => 'Attempting self revocation.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_REVOCATION_DENIED');

        $revoke = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-grants/revocation', ['grant_type' => 'ROLE', 'grant_id' => $assignment->id, 'reason' => 'No longer required for this role.']);
        $revoke->assertStatus(200)->assertJsonPath('revocation.status', 'REVOKED');

        // Idempotent -- revoking an already-revoked grant returns its
        // current state rather than erroring or double-writing.
        $again = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-grants/revocation', ['grant_type' => 'ROLE', 'grant_id' => $assignment->id, 'reason' => 'Repeat revocation attempt.']);
        $again->assertStatus(200)->assertJsonPath('revocation.status', 'REVOKED');
        $this->assertDatabaseCount('user_role_assignments', 2); // the subject's + owner's own, no duplicates.
    }

    public function test_offboarding_revokes_every_active_grant_is_idempotent_and_denies_self_offboard(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ACCGOV-0005');
        $this->openReview($ctx['owner']);
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0005@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);
        UserCapabilityAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id,
            'capability' => 'SELLER', 'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id,
        ]);

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/offboarding', ['user_id' => $ctx['owner']->id, 'reason' => 'Attempting to offboard myself.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_OFFBOARD_DENIED');

        $offboarded = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/offboarding', ['user_id' => $subject->id, 'reason' => 'Access-only exit, security incident.']);
        $offboarded->assertStatus(200)
            ->assertJsonPath('offboarding.membershipRevoked', true)
            ->assertJsonPath('offboarding.roleAssignmentsRevoked', 1)
            ->assertJsonPath('offboarding.capabilityAssignmentsRevoked', 1);
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('user_role_assignments', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('user_capability_assignments', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id, 'status' => 'REVOKED']);

        // Idempotent -- nothing left active to revoke, a genuine no-op.
        $again = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/offboarding', ['user_id' => $subject->id, 'reason' => 'Repeat offboarding attempt.']);
        $again->assertStatus(200)
            ->assertJsonPath('offboarding.membershipRevoked', false)
            ->assertJsonPath('offboarding.roleAssignmentsRevoked', 0)
            ->assertJsonPath('offboarding.capabilityAssignmentsRevoked', 0);
    }
}

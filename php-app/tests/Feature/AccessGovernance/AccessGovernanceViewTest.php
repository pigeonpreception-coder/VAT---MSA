<?php

namespace Tests\Feature\AccessGovernance;

use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\OrganisationMembership;
use App\Models\OrganisationRole;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers the real Blade UI for AccessGovernanceService -- RT-005 (the
 * self-directed red-team assessment, see docs/MIGRATION_MATRIX.md):
 * App\Http\Controllers\AccessGovernance\AccessGovernanceViewController /
 * resources/views/access-governance/index.blade.php. Fixture helpers
 * mirror tests/Feature/AccessGovernance/AccessGovernanceTest.php's own
 * conventions exactly (the JSON-API coverage for the same service).
 */
class AccessGovernanceViewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

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

    private function makeMember(Taxpayer $taxpayer, Organisation $organisation, string $email, User $assignedBy, string $role = 'TAXPAYER_STAFF'): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email, 'password' => bcrypt('password'),
            'role' => $role, 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $user->id,
            'role_code' => $role, 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
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
        $this->actingAs($actor)->withFreshStepUp()->postJson('/api/v1/access-reviews')->assertStatus(201);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/access-governance')->assertRedirect('/login');
    }

    public function test_a_role_without_access_governance_read_is_forbidden(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0001');

        $this->actingAs($ctx['owner'])->get('/access-governance')->assertOk(); // sanity: owner does hold it
        $viewer = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'viewer-0001@test.test', $ctx['owner'], 'TAXPAYER_VIEWER');
        $this->actingAs($viewer)->get('/access-governance')->assertForbidden();
    }

    /**
     * The red team finding's own named example role: TAXPAYER_ACCOUNTANT
     * holds access-governance:read but NOT administration:read
     * (App\Support\Access\Permissions::CONTROL_PLANE_PERMISSIONS). Before
     * this fix, there was no page reachable for this exact role at all --
     * this is the direct regression test for that gap, not just a
     * generic permission check.
     */
    public function test_an_accountant_who_cannot_reach_administration_can_still_reach_access_governance(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0002');
        $accountant = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'accountant-0002@test.test', $ctx['owner'], 'TAXPAYER_ACCOUNTANT');

        $this->actingAs($accountant)->get('/administration')->assertForbidden();

        $response = $this->actingAs($accountant)->get('/access-governance');
        $response->assertOk()->assertViewIs('access-governance.index');
        $response->assertSee('Request access');
        // Manage-gated controls stay hidden for a read-only holder.
        $response->assertDontSee('Offboard a user');
    }

    public function test_requesting_access_through_the_form_creates_a_pending_request(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0003');
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0003@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);

        $response = $this->actingAs($ctx['owner'])->post(route('access-governance.requests.store'), [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);

        $response->assertRedirect(route('access-governance.index'));
        $this->assertDatabaseHas('access_requests', ['organisation_id' => $ctx['organisation']->id, 'subject_user_id' => $staff->id, 'status' => 'PENDING_MANAGER']);
    }

    public function test_deciding_a_request_through_the_form_approves_and_creates_a_role_assignment(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0004');
        $this->openReview($ctx['owner']);
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0004@test.test', $ctx['owner']);
        $approver = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'approver-0004@test.test', $ctx['owner'], 'TAXPAYER_ADMIN');
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        $this->actingAs($ctx['owner'])->post(route('access-governance.requests.store'), [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);
        $requestId = $this->getAccessRequestId($ctx['organisation']->id, $staff->id);

        $response = $this->actingAs($approver)->withFreshStepUp()->post(route('access-governance.requests.decision', $requestId), [
            'decision' => 'APPROVE', 'reason' => 'Justified and verified.',
        ]);

        $response->assertRedirect(route('access-governance.index'));
        $this->assertDatabaseHas('access_requests', ['id' => $requestId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('user_role_assignments', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $staff->id, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE']);
    }

    public function test_self_deciding_a_request_through_the_form_is_a_friendly_error_not_a_crash(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0005');
        $this->openReview($ctx['owner']);
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0005@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        $this->actingAs($ctx['owner'])->post(route('access-governance.requests.store'), [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);
        $requestId = $this->getAccessRequestId($ctx['organisation']->id, $staff->id);

        $response = $this->actingAs($ctx['owner'])->withFreshStepUp()->post(route('access-governance.requests.decision', $requestId), [
            'decision' => 'APPROVE', 'reason' => 'Self decision attempt.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('access_requests', ['id' => $requestId, 'status' => 'PENDING_MANAGER']);
    }

    public function test_decision_controls_require_step_up(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0006');
        $this->openReview($ctx['owner']);
        $staff = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'staff-0006@test.test', $ctx['owner']);
        $approver = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'approver-0006@test.test', $ctx['owner'], 'TAXPAYER_ADMIN');
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        $this->actingAs($ctx['owner'])->post(route('access-governance.requests.store'), [
            'subject_user_id' => $staff->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);
        $requestId = $this->getAccessRequestId($ctx['organisation']->id, $staff->id);

        $response = $this->actingAs($approver)->post(route('access-governance.requests.decision', $requestId), [
            'decision' => 'APPROVE', 'reason' => 'Attempting without a confirmed session.',
        ]);

        $response->assertRedirect(route('security.mfa', ['redirect_to' => url('/')]));
        $this->assertDatabaseHas('access_requests', ['id' => $requestId, 'status' => 'PENDING_MANAGER']);
    }

    public function test_certifying_through_the_form_retains_or_revokes(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0007');
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0007@test.test', $ctx['owner']);
        $review = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/access-reviews');
        $reviewId = $review->json('review.id');

        $response = $this->actingAs($ctx['owner'])->withFreshStepUp()->post(route('access-governance.certifications.store'), [
            'review_id' => $reviewId, 'subject_user_id' => $subject->id, 'disposition' => 'RETAIN', 'finding' => '',
        ]);

        $response->assertRedirect(route('access-governance.index'));
        $this->assertDatabaseHas('access_certifications', ['access_review_id' => $reviewId, 'subject_user_id' => $subject->id, 'disposition' => 'RETAIN']);
    }

    public function test_revoking_a_role_grant_through_the_form(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0008');
        $this->openReview($ctx['owner']);
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0008@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner']);
        $assignment = UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);

        $response = $this->actingAs($ctx['owner'])->withFreshStepUp()->post(route('access-governance.revocations.store'), [
            'grant_type' => 'ROLE', 'grant_id' => $assignment->id, 'reason' => 'No longer required for this role.',
        ]);

        $response->assertRedirect(route('access-governance.index'));
        $this->assertDatabaseHas('user_role_assignments', ['id' => $assignment->id, 'status' => 'REVOKED']);
    }

    public function test_offboarding_through_the_form_revokes_membership_and_grants(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0009');
        $this->openReview($ctx['owner']);
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0009@test.test', $ctx['owner']);

        $response = $this->actingAs($ctx['owner'])->withFreshStepUp()->post(route('access-governance.offboarding.store'), [
            'user_id' => $subject->id, 'reason' => 'Access-only exit, security incident.',
        ]);

        $response->assertRedirect(route('access-governance.index'));
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id, 'status' => 'REVOKED']);
    }

    public function test_the_page_lists_requests_reviews_and_active_grants(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-AGVIEW-0010');
        $this->openReview($ctx['owner']);
        $subject = $this->makeMember($ctx['taxpayer'], $ctx['organisation'], 'subject-0010@test.test', $ctx['owner']);
        $role = $this->makeOrganisationRole($ctx['organisation'], $ctx['owner'], 'Branch VAT Reviewer');
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $subject->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);
        $this->actingAs($ctx['owner'])->post(route('access-governance.requests.store'), [
            'subject_user_id' => $subject->id, 'role_id' => $role->id, 'justification' => 'A genuinely valid justification string.',
        ]);

        $response = $this->actingAs($ctx['owner'])->get('/access-governance');

        $response->assertOk();
        $response->assertSee($subject->name);
        $response->assertSee('Branch VAT Reviewer');
        $response->assertSee('Quarterly access review');
    }

    private function getAccessRequestId(string $organisationId, string $subjectUserId): string
    {
        return \Illuminate\Support\Facades\DB::table('access_requests')
            ->where('organisation_id', $organisationId)->where('subject_user_id', $subjectUserId)
            ->orderByDesc('requested_at')->value('id');
    }
}

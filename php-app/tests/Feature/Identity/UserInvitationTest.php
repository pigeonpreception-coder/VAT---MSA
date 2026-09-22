<?php

namespace Tests\Feature\Identity;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers Module 1 Identity's ProvisionUser (App\Services\Identity\
 * UserInvitationService, ported from lib/data/identity-repository.ts's
 * inviteUser/claimInvitation), both its JSON invite half
 * (App\Http\Controllers\Identity\UserInvitationController) and its Blade
 * claim half (App\Http\Controllers\Identity\InvitationClaimController) --
 * see the service's own doc comment for why claiming sets a real password
 * rather than trusting a platform-asserted identity the way source did.
 */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, admin: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@invitetest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@invitetest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin');
    }

    public function test_invite_route_requires_authentication(): void
    {
        $this->postJson('/api/v1/organisations/some-id/invitations')->assertUnauthorized();
    }

    public function test_a_role_without_organisations_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@invitetest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'new-hire@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertForbidden();
    }

    public function test_invite_without_a_fresh_step_up_is_locked(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0002');

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'new-hire@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertStatus(423);
        $this->assertDatabaseCount('user_invitations', 0);
    }

    public function test_an_organisation_admin_can_invite_a_new_user(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0003');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'new-hire@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('invitation.email', 'new-hire@invitetest.test')
            ->assertJsonPath('invitation.role_code', 'TAXPAYER_STAFF')
            ->assertJsonPath('invitation.status', 'PENDING');
        $token = $response->json('invitation.claim_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('user_invitations', [
            'organisation_id' => $org['organisation']->id, 'email' => 'new-hire@invitetest.test', 'claim_token' => $token, 'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'USER_INVITED']);
    }

    public function test_inviting_an_email_that_already_belongs_to_a_user_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0004');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => $org['admin']->email, 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertStatus(409);
    }

    public function test_inviting_the_same_pending_email_twice_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0005');
        $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'repeat@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'repeat@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, UserInvitation::where('email', 'repeat@invitetest.test')->count());
    }

    public function test_inviting_with_a_non_assignable_role_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0006');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$org['organisation']->id}/invitations", [
            'email' => 'escalation@invitetest.test', 'role_code' => 'SUPER_ADMIN',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('user_invitations', 0);
    }

    public function test_an_admin_cannot_invite_into_another_taxpayers_organisation(): void
    {
        $orgA = $this->makeOrganisation('VAT-INV-0007');
        $orgB = $this->makeOrganisation('VAT-INV-0008');

        $response = $this->actingAs($orgA['admin'])->withFreshStepUp()->postJson("/api/v1/organisations/{$orgB['organisation']->id}/invitations", [
            'email' => 'cross-tenant@invitetest.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);

        $response->assertForbidden();
    }

    private function inviteAndReturnToken(User $admin, Organisation $organisation, string $email = 'claimant@invitetest.test'): string
    {
        $response = $this->actingAs($admin)->withFreshStepUp()->postJson("/api/v1/organisations/{$organisation->id}/invitations", [
            'email' => $email, 'role_code' => 'TAXPAYER_STAFF',
        ]);
        // actingAs() persists across requests in the same test, but the
        // claim routes wear 'guest' middleware (the whole point is that
        // the claimant has no session yet) -- log out the inviting admin
        // first, or the guest middleware silently redirects every claim
        // attempt below away before it ever reaches the controller.
        $this->post('/logout');

        return $response->json('invitation.claim_token');
    }

    public function test_the_claim_form_is_reachable_without_authentication(): void
    {
        $this->get('/invitations/claim?token=some-token')->assertOk()->assertSee('Claim your invitation');
    }

    public function test_claiming_a_valid_invitation_creates_a_real_account(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0009');
        $token = $this->inviteAndReturnToken($org['admin'], $org['organisation']);

        $response = $this->post('/invitations/claim', [
            'token' => $token, 'name' => 'Claimant Person',
            'password' => 'CorrectHorse1', 'password_confirmation' => 'CorrectHorse1',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $user = User::where('email', 'claimant@invitetest.test')->firstOrFail();
        $this->assertSame('Claimant Person', $user->name);
        $this->assertSame('TAXPAYER_STAFF', $user->role);
        $this->assertSame($org['taxpayer']->id, $user->taxpayer_id);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertDatabaseHas('user_invitations', ['claim_token' => $token, 'status' => 'CLAIMED', 'claimed_by_user_id' => $user->id]);
        $this->assertDatabaseHas('organisation_memberships', [
            'organisation_id' => $org['organisation']->id, 'user_id' => $user->id, 'role_code' => 'TAXPAYER_STAFF', 'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'USER_PROVISIONED', 'resource_id' => $user->id]);

        $loginResponse = $this->post('/login', ['email' => 'claimant@invitetest.test', 'password' => 'CorrectHorse1']);
        $loginResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_claiming_with_an_unknown_token_shows_a_generic_error(): void
    {
        $response = $this->post('/invitations/claim', [
            'token' => 'not-a-real-token', 'name' => 'Nobody',
            'password' => 'CorrectHorse1', 'password_confirmation' => 'CorrectHorse1',
        ]);

        $response->assertSessionHasErrors(['token' => 'This invitation link is invalid or has expired.']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_claiming_an_already_claimed_invitation_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0010');
        $token = $this->inviteAndReturnToken($org['admin'], $org['organisation']);
        $this->post('/invitations/claim', [
            'token' => $token, 'name' => 'First Claim',
            'password' => 'CorrectHorse1', 'password_confirmation' => 'CorrectHorse1',
        ]);

        $response = $this->post('/invitations/claim', [
            'token' => $token, 'name' => 'Second Claim',
            'password' => 'CorrectHorse2', 'password_confirmation' => 'CorrectHorse2',
        ]);

        $response->assertSessionHasErrors(['token' => 'This invitation link is invalid or has expired.']);
        $this->assertSame(1, User::where('email', 'claimant@invitetest.test')->count());
    }

    public function test_claiming_an_expired_invitation_is_rejected_and_marked_expired(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0011');
        $token = $this->inviteAndReturnToken($org['admin'], $org['organisation']);
        UserInvitation::where('claim_token', $token)->update(['expires_at' => now()->subDay()]);

        $response = $this->post('/invitations/claim', [
            'token' => $token, 'name' => 'Too Late',
            'password' => 'CorrectHorse1', 'password_confirmation' => 'CorrectHorse1',
        ]);

        $response->assertSessionHasErrors(['token' => 'This invitation link is invalid or has expired.']);
        $this->assertDatabaseHas('user_invitations', ['claim_token' => $token, 'status' => 'EXPIRED']);
        $this->assertDatabaseMissing('users', ['email' => 'claimant@invitetest.test']);
    }

    public function test_claiming_with_a_weak_password_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-INV-0012');
        $token = $this->inviteAndReturnToken($org['admin'], $org['organisation']);

        $response = $this->post('/invitations/claim', [
            'token' => $token, 'name' => 'Weak Password', 'password' => 'short', 'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'claimant@invitetest.test']);
        $this->assertDatabaseHas('user_invitations', ['claim_token' => $token, 'status' => 'PENDING']);
    }
}

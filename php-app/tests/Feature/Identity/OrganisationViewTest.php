<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\MfaTotpCredential;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationMembership;
use App\Models\RegistrationApplication;
use App\Models\Taxpayer;
use App\Models\User;
use App\Support\Access\Totp;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers the real Blade UI bundling Module 1's Organisations/Branches/
 * Memberships/Taxpayer-suspension/Identity-snapshot services --
 * App\Http\Controllers\Identity\OrganisationViewController /
 * resources/views/organisations/** -- the frontend UI build-out's ninth
 * slice, the third fresh, smaller PR (after Disputes, Obligations). Reuses
 * BranchManagementTest's and TaxpayerSuspensionTest's own fixture
 * patterns, adapted to hit the Blade routes.
 */
class OrganisationViewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // Deploy-time reference data (IdentityProviderSeeder's own doc
        // comment: no command anywhere ever creates an identity provider
        // row), needed here since the index page's snapshot renders it.
        $this->seed(IdentityProviderSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, headOffice: Branch} */
    private function ownerWithOrganisation(string $vatNumber = 'VAT-VIEW-ORG-0001'): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);
        $headOffice = Branch::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'HEAD', 'name' => 'Head Office', 'address' => $taxpayer->address, 'status' => 'ACTIVE', 'is_head_office' => true]);

        return compact('taxpayer', 'organisation', 'headOffice');
    }

    private function taxpayerOwner(string $taxpayerId, string $email = null): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email ?? 'owner-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds identity:read but not organisations:manage or taxpayers:suspend -- the read-only fixture. */
    private function taxpayerViewer(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'viewer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_organisations_list_requires_authentication(): void
    {
        $this->get('/organisations')->assertRedirect('/login');
    }

    public function test_the_index_page_shows_the_identity_snapshot_and_the_organisations_list(): void
    {
        $fx = $this->ownerWithOrganisation();

        $response = $this->actingAs($this->pilotAdmin())->get('/organisations');

        $response->assertOk()->assertViewIs('organisations.index');
        $response->assertSee('Identity providers');
        $response->assertSee('ITAS identity provider');
        $response->assertSee($fx['organisation']->legal_name);
    }

    /**
     * Gap-finding pass (2026-09-23): app/organisations/page.tsx renders a
     * metric-grid (canonical organisations, active branches, linked
     * identities, pending registrations) and a Capabilities column on the
     * registry table -- this view had neither, even though
     * IdentityFoundationSnapshotService::getSnapshot() already returns the
     * data needed for all four tiles.
     */
    public function test_the_index_page_renders_its_metric_tiles_and_capabilities_column(): void
    {
        $fx = $this->ownerWithOrganisation();
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $fx['organisation']->id, 'capability' => 'BUYER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'effective_to' => null,
        ]);
        $admin = $this->pilotAdmin();
        RegistrationApplication::create([
            'id' => (string) Str::uuid(), 'idempotency_key' => Str::random(20), 'request_hash' => str_repeat('a', 64),
            'vat_number' => 'VAT-VIEW-ORG-PENDING', 'tin' => 'TIN-VAT-VIEW-ORG-PENDING', 'legal_name' => 'Pending Applicant Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY', 'address' => '1 Test Street',
            'email' => 'pending-app@test.test', 'status' => 'PENDING_VERIFICATION', 'verification_source' => 'MANUAL',
            'submitted_by' => $admin->id, 'submitted_at' => now(),
        ]);
        RegistrationApplication::create([
            'id' => (string) Str::uuid(), 'idempotency_key' => Str::random(20), 'request_hash' => str_repeat('b', 64),
            'vat_number' => 'VAT-VIEW-ORG-APPROVED', 'tin' => 'TIN-VAT-VIEW-ORG-APPROVED', 'legal_name' => 'Approved Applicant Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY', 'address' => '1 Test Street',
            'email' => 'approved-app@test.test', 'status' => 'APPROVED', 'verification_source' => 'MANUAL',
            'submitted_by' => $admin->id, 'submitted_at' => now(), 'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/organisations');

        $response->assertOk();
        $response->assertSeeInOrder(['Canonical organisations', '1']);
        $response->assertSeeInOrder(['Active branches', '1']);
        $response->assertSeeInOrder(['Pending registrations', '1']);
        $response->assertSee('Buyer');
    }

    /**
     * Gap-finding pass (2026-09-23): app/organisations/page.tsx's own
     * PageHeader `actions` slot (a "New registration" button) had no
     * Laravel equivalent -- the page had no header-level call to action
     * at all. Now links to the /registrations page built earlier this
     * pass (matching source's own equivalent target, adjusted since this
     * port folds the create form onto the index page rather than a
     * separate /registrations/new route).
     */
    public function test_the_index_page_has_a_new_registration_action_link(): void
    {
        $admin = $this->pilotAdmin();

        $response = $this->actingAs($admin)->get('/organisations');

        $response->assertOk();
        $response->assertSee('New registration');
        $response->assertSee(route('registrations.index'), false);
    }

    public function test_a_taxpayer_can_view_their_own_organisation_with_its_branch_and_membership(): void
    {
        $fx = $this->ownerWithOrganisation();
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $response = $this->actingAs($owner)->get(route('organisations.show', $fx['organisation']->id));

        $response->assertOk()->assertViewIs('organisations.show');
        $response->assertSee($fx['headOffice']->name);
        $response->assertSee('Add branch');
        $response->assertSee('Assign membership');
    }

    public function test_a_read_only_viewer_sees_no_management_forms(): void
    {
        $fx = $this->ownerWithOrganisation();
        $viewer = $this->taxpayerViewer($fx['taxpayer']->id);

        $response = $this->actingAs($viewer)->get(route('organisations.show', $fx['organisation']->id));

        $response->assertOk();
        $response->assertDontSee('Add branch');
        $response->assertDontSee('Assign membership');
        $response->assertDontSee('Taxpayer suspension');
    }

    public function test_a_taxpayer_cannot_view_another_taxpayers_organisation(): void
    {
        $fxA = $this->ownerWithOrganisation('VAT-VIEW-ORG-0002');
        $fxB = $this->ownerWithOrganisation('VAT-VIEW-ORG-0003');
        $ownerA = $this->taxpayerOwner($fxA['taxpayer']->id);

        // Out-of-scope but existing -- the RT-002 clean-403 page, not a
        // 404, matching OrganisationService::get()'s own
        // TaxpayerScope::requireTaxpayer() AuthorizationException, the same
        // service-level-exception precedent as VAT Returns/Audit Cases.
        $this->actingAs($ownerA)->get(route('organisations.show', $fxB['organisation']->id))->assertForbidden();
    }

    public function test_an_organisation_admin_can_create_and_deactivate_a_non_head_office_branch(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0004');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $create = $this->actingAs($owner)->post(route('organisations.branches.store', $fx['organisation']->id), [
            'code' => 'sw-01', 'name' => 'Swakopmund Branch', 'address' => '1 Coastal Road, Swakopmund',
        ]);
        $create->assertRedirect(route('organisations.show', $fx['organisation']->id));
        $branch = Branch::where('organisation_id', $fx['organisation']->id)->where('code', 'SW-01')->firstOrFail();

        $deactivate = $this->actingAs($owner)->post(route('organisations.branches.update', [$fx['organisation']->id, $branch->id]), [
            '_method' => 'PATCH', 'status' => 'INACTIVE',
        ]);
        $deactivate->assertRedirect(route('organisations.show', $fx['organisation']->id));
        $this->assertSame('INACTIVE', $branch->fresh()->status);
    }

    public function test_creating_a_branch_with_a_duplicate_code_is_a_friendly_form_error(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0005');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $response = $this->actingAs($owner)->post(route('organisations.branches.store', $fx['organisation']->id), [
            'code' => 'HEAD', 'name' => 'Duplicate', 'address' => '1 Somewhere',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertSame(1, Branch::where('organisation_id', $fx['organisation']->id)->count());
    }

    public function test_the_head_office_branch_cannot_be_deactivated_via_the_view(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0006');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $response = $this->actingAs($owner)->post(route('organisations.branches.update', [$fx['organisation']->id, $fx['headOffice']->id]), [
            '_method' => 'PATCH', 'status' => 'INACTIVE',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
        $this->assertSame('ACTIVE', $fx['headOffice']->fresh()->status);
    }

    public function test_assigning_a_membership_with_a_confirmed_session_creates_a_real_membership(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0007');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);
        $newMember = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff Member', 'email' => 'staff@view-org-0007.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)
            ->withFreshStepUp()
            ->post(route('organisations.memberships.store', $fx['organisation']->id), [
                'email' => 'staff@view-org-0007.test', 'role_code' => 'TAXPAYER_STAFF',
            ]);

        $response->assertRedirect(route('organisations.show', $fx['organisation']->id));
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $fx['organisation']->id, 'user_id' => $newMember->id, 'role_code' => 'TAXPAYER_STAFF']);
    }

    /**
     * 2026-09-15 TOTP cutover: walks the real enrol -> verify -> confirm
     * step-up sequence App\Http\Middleware\EnsureFreshStepUp now sends a
     * not-yet-enrolled user through, proving `redirect_to` survives every
     * hop and the user lands back on the real organisation page at the
     * end -- not a 404/405 from redirect()->intended() replaying the
     * originally blocked POST's own URL as a GET (the exact bug this test
     * originally existed to catch, back when the gate was
     * ConfirmPasswordController).
     */
    public function test_assigning_a_membership_without_a_fresh_step_up_redirects_through_totp_setup_and_back_to_the_organisation_page(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0008');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id, 'owner-0008@test.test');
        User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff Member', 'email' => 'staff@view-org-0008.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        $organisationPage = route('organisations.show', $fx['organisation']->id);

        $this->actingAs($owner)->get($organisationPage);

        $blocked = $this->actingAs($owner)->post(route('organisations.memberships.store', $fx['organisation']->id), [
            'email' => 'staff@view-org-0008.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);
        $blocked->assertRedirect(route('security.mfa', ['redirect_to' => $organisationPage]));

        $mfaPage = $this->actingAs($owner)->get(route('security.mfa', ['redirect_to' => $organisationPage]));
        $mfaPage->assertSee($organisationPage, false);

        $this->actingAs($owner)->post(route('security.mfa.enroll'), ['redirect_to' => $organisationPage]);
        $secret = MfaTotpCredential::find($owner->id)->secret_base32;
        $this->actingAs($owner)->post(route('security.mfa.verify'), ['code' => Totp::generateCode($secret), 'redirect_to' => $organisationPage]);

        // Advance past the verification code's own 30-second step so the
        // step-up confirmation below is a genuinely fresh, unused code --
        // same anti-replay concern MfaViewTest's own flow test handles.
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $confirm = $this->actingAs($owner)->post(route('security.step-up'), ['code' => Totp::generateCode($secret), 'redirect_to' => $organisationPage]);
        $confirm->assertRedirect($organisationPage);
        Carbon::setTestNow();

        // With a fresh step-up now confirmed, retrying the original action succeeds.
        $retry = $this->actingAs($owner)->post(route('organisations.memberships.store', $fx['organisation']->id), [
            'email' => 'staff@view-org-0008.test', 'role_code' => 'TAXPAYER_STAFF',
        ]);
        $retry->assertRedirect($organisationPage);
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $fx['organisation']->id, 'role_code' => 'TAXPAYER_STAFF']);
    }

    public function test_assigning_a_national_role_via_membership_is_a_friendly_form_error(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0009');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);
        User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff Member', 'email' => 'staff@view-org-0009.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)
            ->withFreshStepUp()
            ->post(route('organisations.memberships.store', $fx['organisation']->id), [
                'email' => 'staff@view-org-0009.test', 'role_code' => 'NAMRA_SYSTEM_SUPPORT',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('role_code');
        $this->assertDatabaseCount('organisation_memberships', 0);
    }

    public function test_assigning_a_membership_to_an_unknown_email_is_a_friendly_form_error(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0010');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $response = $this->actingAs($owner)
            ->withFreshStepUp()
            ->post(route('organisations.memberships.store', $fx['organisation']->id), [
                'email' => 'nobody@view-org-0010.test', 'role_code' => 'TAXPAYER_STAFF',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('organisation_memberships', 0);
    }

    public function test_a_pilot_admin_can_suspend_a_taxpayer_from_the_organisation_page(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0011');
        $admin = $this->pilotAdmin();

        $showBefore = $this->actingAs($admin)->get(route('organisations.show', $fx['organisation']->id));
        $showBefore->assertSee('Suspend taxpayer');

        $response = $this->actingAs($admin)
            ->withFreshStepUp()
            ->post(route('organisations.taxpayer-suspension.store', $fx['organisation']->id), [
                'taxpayer_id' => $fx['taxpayer']->id, 'reason' => 'Flagged for compliance review.',
            ]);

        $response->assertRedirect(route('organisations.show', $fx['organisation']->id));
        $this->assertSame('SUSPENDED', $fx['taxpayer']->fresh()->vat_status);

        $showAfter = $this->actingAs($admin)->get(route('organisations.show', $fx['organisation']->id));
        $showAfter->assertSee('already suspended');
    }

    public function test_a_taxpayer_owner_never_sees_the_suspension_card(): void
    {
        $fx = $this->ownerWithOrganisation('VAT-VIEW-ORG-0012');
        $owner = $this->taxpayerOwner($fx['taxpayer']->id);

        $response = $this->actingAs($owner)->get(route('organisations.show', $fx['organisation']->id));

        $response->assertOk();
        $response->assertDontSee('Suspend taxpayer');
    }
}

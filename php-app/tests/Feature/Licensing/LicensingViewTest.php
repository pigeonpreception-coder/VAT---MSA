<?php

namespace Tests\Feature\Licensing;

use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for LicensingService (Phase 12 slice 1) --
 * App\Http\Controllers\Licensing\LicensingViewController /
 * resources/views/licensing/index.blade.php -- the frontend UI
 * build-out's twelfth slice, the sixth fresh, smaller PR. Reuses
 * LicensingTest's own makeLicensedOrganisation fixture pattern.
 */
class LicensingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(LicensePlanSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, license: OrganisationLicense} */
    private function makeLicensedOrganisation(string $vatNumber, string $state = 'ACTIVE'): array
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
        $license = OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => $state, 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        LicenseUsage::create([
            'id' => (string) Str::uuid(), 'organisation_license_id' => $license->id, 'organisation_id' => $organisation->id,
            'metric_key' => 'USER_SEATS', 'period_key' => '2026-Q3', 'used_value' => 4, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'license');
    }

    /** Holds licensing:read (via WORKSPACE_READ) but not licensing:manage -- the read-only fixture. */
    private function taxpayerAccountant(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Accountant', 'email' => 'accountant-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither licensing:read nor licensing:manage. */
    private function taxpayerStaff(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_licensing_page_requires_authentication(): void
    {
        $this->get('/licensing')->assertRedirect('/login');
    }

    public function test_a_role_without_licensing_read_is_forbidden(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0001');

        $this->actingAs($this->taxpayerStaff($fx['taxpayer']->id))->get('/licensing')->assertForbidden();
    }

    public function test_the_page_renders_the_real_license_entitlements_and_usage(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0002');

        $response = $this->actingAs($fx['owner'])->get('/licensing');

        $response->assertOk()->assertViewIs('licensing.index');
        $response->assertSee('Professional Pilot');
        $response->assertSee('Organisation administration');
        $response->assertSee('User Seats');
        $response->assertSee('2026-Q3');
    }

    public function test_a_read_only_role_sees_the_page_but_no_state_change_form(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0003');

        $response = $this->actingAs($this->taxpayerAccountant($fx['taxpayer']->id))->get('/licensing');

        $response->assertOk();
        $response->assertDontSee('Change licence state');
    }

    public function test_the_state_dropdown_only_offers_actions_valid_from_active(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0004', 'ACTIVE');

        $response = $this->actingAs($fx['owner'])->get('/licensing');

        $response->assertOk();
        $response->assertSee('Suspend');
        $response->assertSee('Renew');
        $response->assertDontSee('Activate');
    }

    public function test_suspending_a_license_with_a_confirmed_session_updates_its_real_state(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0005', 'ACTIVE');

        $response = $this->actingAs($fx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('licensing.state.store'), ['action' => 'SUSPEND', 'reason' => 'Non-payment flagged for review.']);

        $response->assertRedirect(route('licensing.index'));
        $this->assertSame('SUSPENDED', $fx['license']->fresh()->state);

        $show = $this->actingAs($fx['owner'])->get('/licensing');
        $show->assertSee('Suspended');
    }

    public function test_state_changes_are_step_up_gated(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0006', 'ACTIVE');

        $response = $this->actingAs($fx['owner'])->post(route('licensing.state.store'), [
            'action' => 'SUSPEND', 'reason' => 'Attempting without a confirmed session.',
        ]);

        $response->assertRedirect(route('password.confirm'));
        $this->assertSame('ACTIVE', $fx['license']->fresh()->state);
    }

    public function test_an_invalid_transition_is_a_friendly_form_error_not_a_raw_422(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0007', 'ACTIVE');

        // ACTIVATE is not valid from ACTIVE (only from TRIAL/GRACE_PERIOD/
        // PENDING_RENEWAL/SUSPENDED) -- posted directly rather than via the
        // dropdown, which would never offer it from this state.
        $response = $this->actingAs($fx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('licensing.state.store'), ['action' => 'ACTIVATE', 'reason' => 'Invalid transition attempt.']);

        $response->assertRedirect(route('licensing.index'));
        $response->assertSessionHasErrors('form');
        $this->assertSame('ACTIVE', $fx['license']->fresh()->state);
    }

    public function test_a_role_without_licensing_manage_cannot_post_a_state_change(): void
    {
        $fx = $this->makeLicensedOrganisation('VAT-VIEW-LIC-0008', 'ACTIVE');

        $response = $this->actingAs($this->taxpayerAccountant($fx['taxpayer']->id))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('licensing.state.store'), ['action' => 'SUSPEND', 'reason' => 'Should be denied.']);

        $response->assertForbidden();
        $this->assertSame('ACTIVE', $fx['license']->fresh()->state);
    }
}

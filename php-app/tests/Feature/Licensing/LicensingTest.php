<?php

namespace Tests\Feature\Licensing;

use App\Models\LicensePlan;
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
 * Covers App\Services\Licensing\LicensingService (ported from lib/data/
 * control-plane-repository.ts's getEntitlementsSnapshot/getUsageSnapshot/
 * changeLicenseState/upgradeLicense) -- Phase 12's first slice (portals/
 * licensing/governance). Neither `subscriptions` nor an organisation's
 * *first* `organisation_licenses` row has any application write path in
 * either system (see those migrations' own doc comments) -- test fixtures
 * provision them directly, exactly as the source's own demo seed does.
 */
class LicensingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(LicensePlanSeeder::class);
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
        $license = OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        LicenseUsage::create([
            'id' => (string) Str::uuid(), 'organisation_license_id' => $license->id, 'organisation_id' => $organisation->id,
            'metric_key' => 'USER_SEATS', 'period_key' => '2026-Q3', 'used_value' => 4, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        LicenseUsage::create([
            'id' => (string) Str::uuid(), 'organisation_license_id' => $license->id, 'organisation_id' => $organisation->id,
            'metric_key' => 'WORKFLOWS', 'period_key' => '2025-Q1', 'used_value' => 99, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_entitlements_reflect_the_real_plan_and_period_scoped_usage_while_the_usage_endpoint_is_unfiltered(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-LIC-0001');

        $entitlements = $this->actingAs($ctx['owner'])->getJson('/api/v1/licensing/entitlements');
        $entitlements->assertStatus(200)
            ->assertJsonPath('license.plan_code', 'PILOT_PROFESSIONAL')
            ->assertJsonPath('license.state', 'ACTIVE');
        $seats = collect($entitlements->json('entitlements'))->firstWhere('feature_key', 'USER_SEATS');
        $this->assertSame(4, $seats['used_value']);
        $this->assertSame(25, $seats['limit_value']);
        // WORKFLOWS' only usage row is outside the hardcoded '2026-Q3'/'2026-08' period filter, so it reads as zero here.
        $workflow = collect($entitlements->json('entitlements'))->firstWhere('feature_key', 'ADVANCED_WORKFLOW');
        $this->assertSame(0, $workflow['used_value']);

        $usage = $this->actingAs($ctx['owner'])->getJson('/api/v1/licensing/usage');
        $usage->assertStatus(200)->assertJsonCount(2, 'usage');
    }

    public function test_suspend_and_activate_hold_the_real_state_machine_and_reject_illegal_transitions(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-LIC-0002');

        $suspend = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/state', ['action' => 'SUSPEND', 'reason' => 'Non-payment of the current invoice.']);
        $suspend->assertStatus(200)->assertJsonPath('license.state', 'SUSPENDED')->assertJsonPath('license.previous_state', 'ACTIVE');
        $this->assertDatabaseHas('license_events', ['event_type' => 'LICENSE_SUSPENDED', 'from_state' => 'ACTIVE', 'to_state' => 'SUSPENDED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'LICENSE_SUSPENDED']);

        // SUSPEND is not a legal action from an already-SUSPENDED state.
        $illegal = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/state', ['action' => 'SUSPEND', 'reason' => 'Attempting a second suspension.']);
        $illegal->assertStatus(422)->assertJsonPath('code', 'LICENSE_TRANSITION_INVALID');

        $activate = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/state', ['action' => 'ACTIVATE', 'reason' => 'Payment received.']);
        $activate->assertStatus(200)->assertJsonPath('license.state', 'ACTIVE');
    }

    public function test_renew_advances_the_subscriptions_current_period(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-LIC-0003');

        $renew = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/state', ['action' => 'RENEW', 'reason' => 'Annual renewal confirmed by finance.']);
        $renew->assertStatus(200)->assertJsonPath('license.state', 'ACTIVE');

        $subscription = Subscription::where('organisation_id', $ctx['organisation']->id)->firstOrFail();
        $this->assertTrue($subscription->current_period_end->greaterThan(now()->addYear()->subDays(2)));
        $this->assertDatabaseHas('license_events', ['event_type' => 'LICENSE_RENEWED']);
    }

    public function test_upgrade_creates_a_new_versioned_license_row_and_refuses_an_unchanged_plan(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-LIC-0004');
        $enterprisePlan = LicensePlan::create([
            'id' => (string) Str::uuid(), 'code' => 'ENTERPRISE', 'name' => 'Enterprise', 'version' => 1, 'status' => 'ACTIVE',
            'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $originalLicense = OrganisationLicense::where('organisation_id', $ctx['organisation']->id)->firstOrFail();

        $upgrade = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/upgrade', ['license_plan_code' => 'ENTERPRISE']);
        $upgrade->assertStatus(200)->assertJsonPath('license.plan_code', 'ENTERPRISE')->assertJsonPath('license.state', 'ACTIVE');
        $newLicenseId = $upgrade->json('license.license_id');
        $this->assertNotSame($originalLicense->id, $newLicenseId);

        $this->assertDatabaseHas('organisation_licenses', ['id' => $newLicenseId, 'license_plan_id' => $enterprisePlan->id, 'state' => 'ACTIVE']);
        $original = OrganisationLicense::findOrFail($originalLicense->id);
        $this->assertNotNull($original->effective_to);
        // Closing the original row (setting effective_to) must never also
        // silently touch its own effective_from -- see that migration's
        // own doc comment for the real MariaDB auto-update bug this guards.
        $this->assertTrue($original->effective_from->equalTo($originalLicense->effective_from));
        $this->assertDatabaseHas('license_events', ['event_type' => 'LICENSE_PLAN_UPGRADED']);

        $unchanged = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/licensing/upgrade', ['license_plan_code' => 'ENTERPRISE']);
        $unchanged->assertStatus(422)->assertJsonPath('code', 'LICENSE_PLAN_UNCHANGED');
    }

    public function test_licensing_requires_permission_and_is_scoped_to_the_owning_organisation(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-LIC-0005');
        $stranger = $this->makeLicensedOrganisation('VAT-LIC-0006');

        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff', 'email' => 'staff-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($staff)->getJson('/api/v1/licensing/entitlements')->assertStatus(403);

        // A different organisation's owner explicitly requesting this one's scope is denied, not silently redirected.
        $this->actingAs($stranger['owner'])
            ->getJson('/api/v1/licensing/entitlements?organisation_id='.$ctx['organisation']->id)
            ->assertStatus(403);
    }
}

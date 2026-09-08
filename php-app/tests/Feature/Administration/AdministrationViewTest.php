<?php

namespace Tests\Feature\Administration;

use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationLicense;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Administration command centre
 * (App\Http\Controllers\Administration\AdministrationViewController /
 * resources/views/administration/index.blade.php) -- ported from the
 * source's own app/administration/page.tsx + AdministrationActions.tsx.
 * Reuses App\Services\Administration\AdministrationSnapshotService and
 * App\Services\OrganisationAdmin\OrganisationAdminService directly (both
 * already covered end to end by tests/Feature/OrganisationAdmin/
 * OrganisationAdminTest.php and tests/Feature/Administration/
 * AdministrationSnapshotTest.php), so this file's own job is the access
 * gate, the view's own rendering, the two write actions reached through
 * this UI, and the password.confirm step-up substitution.
 */
class AdministrationViewTest extends TestCase
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
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
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
            'metric_key' => 'USER_SEATS', 'period_key' => '2026-Q3', 'used_value' => 1, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@adminview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /** ADMIN_WRITE operations (invite/create-role) are blocked until a current quarterly access review is open -- see App\Support\Licensing\EntitlementGate::assert. */
    private function openReview(User $actor): void
    {
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')
            ->assertStatus(201);
    }

    public function test_the_administration_page_requires_authentication(): void
    {
        $this->get('/administration')->assertRedirect('/login');
    }

    public function test_a_role_without_administration_read_is_denied(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-DENY-0001');
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff@adminview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($staff)->get('/administration')->assertForbidden();
    }

    public function test_the_administration_page_renders_the_full_snapshot(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0001');

        $response = $this->actingAs($org['owner'])->get('/administration');

        $response->assertOk()->assertViewIs('administration.index');
        $response->assertSee('Administration command centre');
        $response->assertSee('Professional Pilot');
        $response->assertSee('User seats');
        $response->assertSee('Licence entitlements and usage');
        $response->assertSee('Invite employee');
        $response->assertSee('Create organisation role');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_an_employee_can_be_invited_with_step_up_confirmed(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0002');
        $this->openReview($org['owner']);

        $response = $this->actingAs($org['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/administration/employees', [
                'employee_number' => 'EMP-004', 'full_name' => 'Synthetic Test User', 'email' => 'synthetic.user@example.test',
            ]);

        $response->assertRedirect('/administration');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-004', 'email' => 'synthetic.user@example.test']);
    }

    public function test_inviting_an_employee_without_step_up_confirmation_is_locked(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0003');

        $response = $this->actingAs($org['owner'])->post('/administration/employees', [
            'employee_number' => 'EMP-005', 'full_name' => 'No Step Up', 'email' => 'no.stepup@example.test',
        ]);

        // A plain form POST doesn't "expect JSON", so Laravel's own
        // RequirePassword middleware redirects to the confirm-password
        // screen here rather than the 423 this codebase's JSON API routes
        // return for the same missing-step-up condition.
        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-005']);
    }

    public function test_a_role_without_employees_manage_cannot_invite_an_employee(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0004');
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Accountant', 'email' => 'accountant@adminview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($accountant)->withSession(['auth.password_confirmed_at' => time()])->post('/administration/employees', [
            'employee_number' => 'EMP-006', 'full_name' => 'Denied', 'email' => 'denied@example.test',
        ])->assertForbidden();
    }

    public function test_an_organisation_role_can_be_created_with_step_up_confirmed(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0005');
        $this->openReview($org['owner']);

        $response = $this->actingAs($org['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/administration/roles', [
                'name' => 'Branch VAT Reviewer', 'description' => 'Reviews branch VAT evidence',
                'permissions' => 'invoices:read, returns:read',
            ]);

        $response->assertRedirect('/administration');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('organisation_roles', ['organisation_id' => $org['organisation']->id, 'name' => 'Branch VAT Reviewer']);
        $this->assertDatabaseHas('organisation_role_permissions', ['permission_code' => 'invoices:read']);
        $this->assertDatabaseHas('organisation_role_permissions', ['permission_code' => 'returns:read']);
    }

    public function test_creating_a_role_with_a_protected_permission_fails_validation(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-SELLER-0006');

        $response = $this->actingAs($org['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/administration/roles', [
                'name' => 'Bad Role', 'description' => 'Attempts a protected permission',
                'permissions' => 'security:manage',
            ]);

        $response->assertRedirect('/administration');
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('organisation_roles', ['name' => 'Bad Role']);
    }
}

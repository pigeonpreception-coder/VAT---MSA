<?php

namespace Tests\Feature\Operations;

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
 * Covers the real Blade UI for Operations > Human Resources Module
 * (App\Http\Controllers\Operations\HumanResourcesViewController /
 * resources/views/operations/human-resources/index.blade.php) -- ported
 * from the source's own app/operations/human-resources/page.tsx +
 * HumanResourcesActions.tsx. Reuses App\Services\OrganisationAdmin\
 * OrganisationAdminService directly (already covered end to end by
 * tests/Feature/OrganisationAdmin/OrganisationAdminTest.php), so this
 * file's own job is the access gate, this second entry point's write
 * flows, and the password.confirm step-up substitution -- the same
 * fixture pattern as tests/Feature/Administration/AdministrationViewTest.php,
 * which reaches the identical two commands from a different screen.
 */
class HumanResourcesViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@hrview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function openReview(User $actor): void
    {
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')->assertStatus(201);
    }

    public function test_the_human_resources_page_requires_authentication(): void
    {
        $this->get('/operations/human-resources')->assertRedirect('/login');
    }

    public function test_a_role_without_employees_read_is_forbidden(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-HR-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Buyer User', 'email' => 'buyer@hrview.test',
            'password' => bcrypt('password'), 'role' => 'BUYER_USER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/operations/human-resources')->assertForbidden();
    }

    public function test_the_page_renders_the_employee_directory(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-HR-0002');

        $response = $this->actingAs($org['owner'])->get('/operations/human-resources');

        $response->assertOk()->assertViewIs('operations.human-resources.index');
        $response->assertSee('Human Resources Module');
        $response->assertSee('Invite an employee');
    }

    public function test_an_employee_can_be_invited_with_step_up_confirmed_and_then_terminated(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-HR-0003');
        $this->openReview($org['owner']);

        $invite = $this->actingAs($org['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/operations/human-resources/employees', [
                'employee_number' => 'EMP-HR-001', 'full_name' => 'Operations Hire', 'email' => 'ops.hire@hrview.test',
            ]);
        $invite->assertRedirect(route('operations.human-resources'));
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-HR-001', 'status' => 'INVITED']);
        $employeeId = \App\Models\Employee::where('employee_number', 'EMP-HR-001')->firstOrFail()->id;

        $terminate = $this->actingAs($org['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/operations/human-resources/employees/{$employeeId}/termination", ['reason' => 'Role no longer required.']);
        $terminate->assertRedirect(route('operations.human-resources'));
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'status' => 'TERMINATED']);
    }

    public function test_inviting_an_employee_without_step_up_confirmation_is_locked(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-HR-0004');

        $response = $this->actingAs($org['owner'])->post('/operations/human-resources/employees', [
            'employee_number' => 'EMP-HR-002', 'full_name' => 'No Step Up', 'email' => 'no.stepup@hrview.test',
        ]);

        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-HR-002']);
    }

    public function test_a_role_without_employees_manage_cannot_invite_an_employee(): void
    {
        $org = $this->makeLicensedOrganisation('VAT-HR-0005');
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Accountant', 'email' => 'accountant@hrview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($accountant)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/operations/human-resources/employees', ['employee_number' => 'EMP-HR-003', 'full_name' => 'Denied', 'email' => 'denied@hrview.test'])
            ->assertForbidden();
    }
}

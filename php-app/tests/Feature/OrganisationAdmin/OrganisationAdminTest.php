<?php

namespace Tests\Feature\OrganisationAdmin;

use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationLicense;
use App\Models\OrganisationMembership;
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
 * Covers App\Services\OrganisationAdmin\OrganisationAdminService (ported
 * from lib/data/control-plane-repository.ts's inviteEmployee/
 * activateEmployee/terminateEmployee/appointAdministrator/
 * createOrganisationRole/listCapabilityGrants/grantCapability/
 * openQuarterlyAccessReview) -- Phase 12 slice 2, also closing out "the
 * rest of Phase 8" (employees, organisation-defined custom roles).
 */
class OrganisationAdminTest extends TestCase
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
    private function makeLicensedOrganisation(string $vatNumber, array $capabilities = []): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach ($capabilities as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
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
            'metric_key' => 'USER_SEATS', 'period_key' => '2026-Q3', 'used_value' => 0, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function openReview(User $actor): void
    {
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')
            ->assertStatus(201);
    }

    public function test_admin_write_commands_are_blocked_until_a_quarterly_access_review_is_opened(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ORGADMIN-0001');

        $blocked = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/employees', [
                'employee_number' => 'EMP-0001', 'full_name' => 'Jane Employee', 'email' => 'jane@test.test',
            ]);
        $blocked->assertStatus(403);

        $this->openReview($ctx['owner']);

        $allowed = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/employees', [
                'employee_number' => 'EMP-0001', 'full_name' => 'Jane Employee', 'email' => 'jane@test.test',
            ]);
        $allowed->assertStatus(201)->assertJsonPath('employee.status', 'INVITED');
    }

    public function test_inviting_activating_and_terminating_an_employee_manages_the_license_seat_lifecycle(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ORGADMIN-0002');
        $this->openReview($ctx['owner']);
        $license = OrganisationLicense::where('organisation_id', $ctx['organisation']->id)->firstOrFail();

        $invite = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/employees', ['employee_number' => 'EMP-0002', 'full_name' => 'Sam Staff', 'email' => 'sam@test.test']);
        $invite->assertStatus(201);
        $employeeId = $invite->json('employee.id');
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $license->id, 'metric_key' => 'USER_SEATS', 'reserved_value' => 1]);

        // Duplicate employee number/email is a conflict.
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/employees', ['employee_number' => 'EMP-0002', 'full_name' => 'Different Name', 'email' => 'other@test.test'])
            ->assertStatus(409);

        $newUser = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Sam Staff', 'email' => 'sam-login@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $activate = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/employees/{$employeeId}/activation", ['user_id' => $newUser->id]);
        $activate->assertStatus(200)->assertJsonPath('employee.status', 'ACTIVE');
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $license->id, 'metric_key' => 'USER_SEATS', 'used_value' => 1, 'reserved_value' => 0]);

        $terminate = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/employees/{$employeeId}/termination", ['reason' => 'Resigned from the organisation.']);
        $terminate->assertStatus(200)->assertJsonPath('employee.status', 'TERMINATED');
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $license->id, 'metric_key' => 'USER_SEATS', 'used_value' => 0]);
        $this->assertDatabaseHas('users', ['id' => $newUser->id, 'status' => 'SUSPENDED']);

        // Terminating the employee record linked to the acting administrator's own identity is denied.
        $selfInvite = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/employees', ['employee_number' => 'EMP-SELF', 'full_name' => 'Self Owner', 'email' => 'self-owner@test.test']);
        $selfEmployeeId = $selfInvite->json('employee.id');
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/employees/{$selfEmployeeId}/activation", ['user_id' => $ctx['owner']->id])
            ->assertStatus(200);
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/employees/{$selfEmployeeId}/termination", ['reason' => 'Attempting to self-offboard.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_OFFBOARD_DENIED');
    }

    public function test_appointing_an_administrator_requires_an_active_employee_and_demotes_the_prior_primary(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ORGADMIN-0003');
        $this->openReview($ctx['owner']);

        $userA = User::create(['id' => (string) Str::uuid(), 'name' => 'Admin A', 'email' => 'admin-a@test.test', 'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE']);
        $userB = User::create(['id' => (string) Str::uuid(), 'name' => 'Admin B', 'email' => 'admin-b@test.test', 'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE']);

        // Appointing a user with no active employee record is refused.
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/administrators', ['user_id' => $userA->id, 'administrator_role_code' => 'PRIMARY', 'is_primary' => true, 'approval_reference' => 'Board resolution 2026-09-01.'])
            ->assertStatus(422);

        foreach ([['EMP-A', $userA], ['EMP-B', $userB]] as [$number, $user]) {
            $invite = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
                ->postJson('/api/v1/organisations/employees', ['employee_number' => $number, 'full_name' => $user->name, 'email' => strtolower($number).'@test.test']);
            $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
                ->postJson("/api/v1/organisations/employees/{$invite->json('employee.id')}/activation", ['user_id' => $user->id])
                ->assertStatus(200);
        }

        $first = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/administrators', ['user_id' => $userA->id, 'administrator_role_code' => 'PRIMARY', 'is_primary' => true, 'approval_reference' => 'Board resolution 2026-09-01.']);
        $first->assertStatus(201)->assertJsonPath('administrator.is_primary', true);
        $firstId = $first->json('administrator.id');

        $second = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/administrators', ['user_id' => $userB->id, 'administrator_role_code' => 'PRIMARY', 'is_primary' => true, 'approval_reference' => 'Board resolution 2026-09-02.']);
        $second->assertStatus(201)->assertJsonPath('administrator.is_primary', true);

        $this->assertDatabaseHas('organisation_administrators', ['id' => $firstId, 'is_primary' => 0]);
    }

    public function test_creating_an_organisation_role_rejects_protected_permissions_and_versions_correctly(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ORGADMIN-0004');
        $this->openReview($ctx['owner']);

        $protected = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/roles', ['name' => 'Sales Lead', 'permissions' => ['vat-rules:manage']]);
        $protected->assertStatus(422)->assertJsonPath('code', 'PROTECTED_PERMISSION');

        $v1 = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/roles', ['name' => 'Sales Lead', 'permissions' => ['commercial:read', 'parties:manage']]);
        $v1->assertStatus(201)->assertJsonPath('role.version', 1)->assertJsonPath('role.status', 'ACTIVE');

        $v2 = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/roles', ['name' => 'Sales Lead', 'permissions' => ['commercial:read']]);
        $v2->assertStatus(201)->assertJsonPath('role.version', 2);
    }

    public function test_granting_a_capability_requires_the_organisations_own_capability_and_active_membership(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-ORGADMIN-0005', ['BUYER']);
        $this->openReview($ctx['owner']);

        $member = User::create(['id' => (string) Str::uuid(), 'name' => 'Member', 'email' => 'member@test.test', 'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE']);

        // The organisation doesn't hold SELLER -- refused before membership is even checked.
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/capabilities', ['user_id' => $member->id, 'capability' => 'SELLER'])
            ->assertStatus(422);

        // BUYER is held by the organisation, but the target user isn't yet an active member.
        $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/capabilities', ['user_id' => $member->id, 'capability' => 'BUYER'])
            ->assertStatus(422);

        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $member->id,
            'role_code' => 'TAXPAYER_STAFF', 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);

        $grant = $this->actingAs($ctx['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/organisations/capabilities', ['user_id' => $member->id, 'capability' => 'BUYER']);
        $grant->assertStatus(201)->assertJsonPath('capability.status', 'ACTIVE');

        $listing = $this->actingAs($ctx['owner'])->getJson('/api/v1/organisations/capabilities');
        $listing->assertStatus(200)->assertJsonCount(1, 'capabilities');
    }
}

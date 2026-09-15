<?php

namespace Tests\Feature\Security;

use App\Models\Organisation;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Regression coverage for a privilege-escalation path this session's
 * broader security review (backlog #10) investigated and found already
 * closed: `App\Domain\OrganisationAdmin\OrganisationAdminValidator::
 * organisationRole()` validates every requested permission against
 * `Permissions::tenantGrantablePermissions()` -- the union of every
 * permission held by a role NOT in `NATIONAL_OR_PLATFORM_ONLY_ROLES` --
 * *before* `OrganisationAdminService::createOrganisationRole()`'s own
 * "is this code in the access_permissions catalogue at all" check ever
 * runs. Two ordinary TAXPAYER_ADMIN accounts in the same organisation
 * (ordinary co-admins, ordinary collusion, no national/platform role
 * involved) cannot create a custom organisation role holding
 * `access-rights:manage` (or any other permission only ever granted to a
 * national/platform-only role, e.g. `platform:manage`, `security:manage`,
 * `authority-governance:manage`) and self/peer-approve their way into it
 * via the Phase 12 access-governance maker-checker flow. This test proves
 * that block holds for the specific access-rights:manage grant this
 * session's own new feature added to the permission catalogue -- not a
 * hypothetical, a concretely attempted exploit chain that failed at the
 * validator, confirmed by the session's own PoC before being kept as this
 * permanent regression test.
 */
class TenantRoleEscalationTest extends TestCase
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

    /** @return array{taxpayer: Taxpayer, organisation: Organisation} */
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

        return compact('taxpayer', 'organisation');
    }

    private function makeAdmin(Taxpayer $taxpayer, Organisation $organisation, string $email): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $user->id,
            'role_code' => 'TAXPAYER_ADMIN', 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $user->id, 'created_at' => now(),
        ]);

        return $user;
    }

    public function test_a_taxpayer_admin_cannot_create_a_custom_role_holding_access_rights_manage(): void
    {
        ['taxpayer' => $taxpayer, 'organisation' => $organisation] = $this->makeLicensedOrganisation('ESC0001');
        $admin = $this->makeAdmin($taxpayer, $organisation, 'admin-esc1@poc.test');

        $response = $this->actingAs($admin)->withFreshStepUp()->post('/administration/roles', [
            'name' => 'Totally Normal Ops Role', 'description' => 'Definitely not a backdoor.',
            'permissions' => 'access-rights:manage',
        ]);

        $response->assertRedirect('/administration');
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('organisation_roles', ['organisation_id' => $organisation->id]);
    }

    #[DataProvider('platformOnlyPermissions')]
    public function test_a_taxpayer_admin_cannot_create_a_custom_role_holding_any_national_or_platform_only_permission(string $permission): void
    {
        ['taxpayer' => $taxpayer, 'organisation' => $organisation] = $this->makeLicensedOrganisation('ESC0002');
        $admin = $this->makeAdmin($taxpayer, $organisation, 'admin-esc2-'.Str::random(6).'@poc.test');

        $response = $this->actingAs($admin)->withFreshStepUp()->post('/administration/roles', [
            'name' => 'Role for '.$permission, 'description' => 'Test.', 'permissions' => $permission,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('organisation_roles', ['organisation_id' => $organisation->id]);
    }

    /** @return list<array{0: string}> */
    public static function platformOnlyPermissions(): array
    {
        return [
            ['access-rights:manage'], ['access-rights:read'], ['platform:manage'], ['platform:read'],
            ['security:manage'], ['authority-governance:manage'],
        ];
    }

    public function test_two_colluding_taxpayer_admins_cannot_reach_access_rights_manage_via_the_maker_checker_flow(): void
    {
        ['taxpayer' => $taxpayer, 'organisation' => $organisation] = $this->makeLicensedOrganisation('ESC0003');
        $adminA = $this->makeAdmin($taxpayer, $organisation, 'admin-a-esc3@poc.test');
        $adminB = $this->makeAdmin($taxpayer, $organisation, 'admin-b-esc3@poc.test');

        // The custom role itself is never created -- the escalation chain
        // is severed at the very first step, before any request/approval
        // could even reference it.
        $this->actingAs($adminA)->withFreshStepUp()->post('/administration/roles', [
            'name' => 'Backdoor Role', 'description' => 'Test.', 'permissions' => 'access-rights:manage',
        ]);
        $this->assertDatabaseMissing('organisation_roles', ['organisation_id' => $organisation->id]);

        $adminA->refresh();
        $adminB->refresh();
        self::assertFalse($adminA->hasAppPermission('access-rights:manage'));
        self::assertFalse($adminB->hasAppPermission('access-rights:manage'));
        $this->actingAs($adminA)->get('/access-rights')->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Identity;

use App\Models\Taxpayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers Module 1 Identity's SuspendUser and its reverse
 * (App\Services\Identity\UserService, ported from lib/data/
 * identity-repository.ts's suspendUser/reactivateUser) -- a standalone,
 * reversible account lockout distinct from App\Services\Identity\
 * TaxpayerService::suspend (tenant-wide) and
 * App\Services\OrganisationAdmin\OrganisationAdminService::terminateEmployee
 * (one-way offboarding).
 */
class UserSuspensionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    private function taxpayerWithUsers(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@usertest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Staff", 'email' => strtolower($vatNumber).'-staff@usertest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'admin', 'staff');
    }

    private function nationalAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'National Admin', 'email' => 'national-admin@usertest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_suspension_and_reactivation_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/users/some-id/suspension')->assertUnauthorized();
        $this->postJson('/api/v1/users/some-id/reactivation')->assertUnauthorized();
    }

    public function test_a_role_without_administration_manage_is_denied(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0001');
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Admin Manage', 'email' => 'accountant@usertest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($accountant)->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Should be denied.']);

        $response->assertForbidden();
    }

    public function test_suspension_without_a_fresh_step_up_is_locked(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0002');

        $response = $this->actingAs($org['admin'])
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'No step-up on this request.']);

        $response->assertStatus(423);
        $this->assertDatabaseHas('users', ['id' => $org['staff']->id, 'status' => 'ACTIVE']);
    }

    public function test_a_taxpayer_admin_can_suspend_and_reactivate_a_user_in_their_own_organisation(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0003');

        $suspendResponse = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Suspected credential compromise.']);
        $suspendResponse->assertOk()->assertJsonPath('suspension.status', 'SUSPENDED');
        $this->assertDatabaseHas('users', ['id' => $org['staff']->id, 'status' => 'SUSPENDED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'USER_SUSPENDED', 'resource_id' => $org['staff']->id]);

        $reactivateResponse = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/reactivation");
        $reactivateResponse->assertOk()->assertJsonPath('reactivation.status', 'ACTIVE');
        $this->assertDatabaseHas('users', ['id' => $org['staff']->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_events', ['action' => 'USER_REACTIVATED', 'resource_id' => $org['staff']->id]);
    }

    public function test_a_national_admin_can_suspend_a_user_in_any_taxpayer(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0004');
        $national = $this->nationalAdmin();

        $response = $this->actingAs($national)->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Escalated by NamRA system support.']);

        $response->assertOk()->assertJsonPath('suspension.status', 'SUSPENDED');
        $this->assertDatabaseHas('users', ['id' => $org['staff']->id, 'status' => 'SUSPENDED']);
    }

    public function test_a_taxpayer_admin_cannot_suspend_a_user_outside_their_taxpayer_scope(): void
    {
        $orgA = $this->taxpayerWithUsers('VAT-US-0005');
        $orgB = $this->taxpayerWithUsers('VAT-US-0006');

        $response = $this->actingAs($orgA['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$orgB['staff']->id}/suspension", ['reason' => 'Cross-tenant suspension attempt.']);

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $orgB['staff']->id, 'status' => 'ACTIVE']);
    }

    public function test_suspending_your_own_account_is_denied(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0007');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['admin']->id}/suspension", ['reason' => 'Attempting self-suspension.']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'SELF_SUSPENSION_DENIED');
        $this->assertDatabaseHas('users', ['id' => $org['admin']->id, 'status' => 'ACTIVE']);
    }

    public function test_suspending_a_nonexistent_user_returns_a_validation_error(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0008');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson('/api/v1/users/'.((string) Str::uuid()).'/suspension', ['reason' => 'Target does not exist.']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'USER_NOT_FOUND');
    }

    public function test_a_suspension_reason_that_is_too_short_is_rejected(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0009');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'hi']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'FIELD_LENGTH_INVALID');
        $this->assertDatabaseHas('users', ['id' => $org['staff']->id, 'status' => 'ACTIVE']);
    }

    public function test_suspending_an_already_suspended_user_is_an_idempotent_no_op(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0010');
        $org['staff']->update(['status' => 'SUSPENDED']);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Repeat check, should be a no-op.']);

        $response->assertOk()->assertJsonPath('suspension.status', 'SUSPENDED');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reactivating_an_already_active_user_is_an_idempotent_no_op(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0011');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/reactivation");

        $response->assertOk()->assertJsonPath('reactivation.status', 'ACTIVE');
        $this->assertDatabaseCount('audit_events', 0);
    }

    /**
     * The real, immediate enforcement effect the TS source calls out
     * explicitly: a suspended user is denied on their very next
     * permission-gated request, via App\Providers\AppServiceProvider's
     * own 'permission' Gate requiring User::isActive() -- no new
     * enforcement point was needed, only this command.
     */
    public function test_a_suspended_user_is_denied_on_their_next_permission_gated_request(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0012');
        $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Immediate-effect regression test.']);

        // TAXPAYER_STAFF genuinely holds invoices:read while ACTIVE --
        // this route only ever 403s once User::isActive() flips false.
        $response = $this->actingAs($org['staff']->refresh())->getJson('/api/v1/invoices');

        $response->assertForbidden();
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $org = $this->taxpayerWithUsers('VAT-US-0013');
        $this->actingAs($org['admin'])->withFreshStepUp()
            ->postJson("/api/v1/users/{$org['staff']->id}/suspension", ['reason' => 'Login-block regression test.']);
        $this->post('/logout');

        $response = $this->post('/login', ['email' => $org['staff']->email, 'password' => 'password']);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }
}

<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Super Admin/NamRA System Admin "grant
 * a user an access right" feature (App\Http\Controllers\Access\
 * AccessRightsViewController / App\Services\Access\UserRoleScopeGrantService /
 * resources/views/access-rights/index.blade.php) -- user's own explicit
 * request to be able to assign a user one of the app's existing roles at
 * a Local Office, Regional/Provincial, National or Global scope. Local
 * Office/Regional scope carries a free-text `scope_label` name only (user's
 * own explicit correction of this feature's first revision, which instead
 * required picking a real `tax_authority_units` office/region row) -- no
 * hierarchy or FK is validated for it.
 */
class AccessRightsViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function superAdmin(string $email = 'super@accessrights.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds access-rights:read/manage delegated from SUPER_ADMIN (user's own explicit request). */
    private function namraSystemAdmin(string $email = 'namra-admin@accessrights.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA System Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither access-rights:read nor access-rights:manage. */
    private function developerPartner(string $email = 'developer@accessrights.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerViewer(string $email = 'viewer@accessrights.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_access_rights_page_requires_authentication(): void
    {
        $this->get('/access-rights')->assertRedirect('/login');
    }

    public function test_a_role_without_access_rights_read_is_denied(): void
    {
        $this->actingAs($this->developerPartner())->get('/access-rights')->assertForbidden();
    }

    public function test_the_page_renders_for_super_admin_with_the_grant_form(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/access-rights');

        $response->assertOk()->assertViewIs('access-rights.index');
        $response->assertSee('Grant an access right');
        $response->assertSee('<caption class="visually-hidden">', false);
    }

    public function test_granting_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->post('/access-rights', [
            'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
        ]);

        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'TAXPAYER_VIEWER']);
    }

    public function test_a_super_admin_can_grant_a_global_role(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
            ]);

        $response->assertRedirect('/access-rights');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'TAXPAYER_ADMIN']);
        $this->assertDatabaseHas('user_role_scope_grants', [
            'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
            'scope_label' => null, 'status' => 'ACTIVE', 'granted_by' => $admin->id,
        ]);
    }

    public function test_a_local_office_grant_requires_a_scope_label(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'LOCAL_OFFICE',
            ]);

        $response->assertRedirect('/access-rights');
        $response->assertSessionHasErrors('scope_label');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'TAXPAYER_VIEWER']);
    }

    public function test_a_super_admin_can_grant_a_local_office_scoped_role_with_a_free_text_label(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'LOCAL_OFFICE',
                'scope_label' => 'Windhoek',
            ]);

        $response->assertRedirect('/access-rights');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'TAXPAYER_ADMIN']);
        $this->assertDatabaseHas('user_role_scope_grants', [
            'user_id' => $target->id, 'scope_level' => 'LOCAL_OFFICE', 'scope_label' => 'Windhoek',
        ]);
    }

    public function test_a_global_grant_ignores_a_submitted_scope_label(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
                'scope_label' => 'Should be ignored',
            ]);

        $response->assertRedirect('/access-rights');
        $this->assertDatabaseHas('user_role_scope_grants', [
            'user_id' => $target->id, 'scope_level' => 'GLOBAL', 'scope_label' => null,
        ]);
    }

    public function test_a_namra_system_admin_can_also_grant_an_access_right(): void
    {
        $admin = $this->namraSystemAdmin();
        $target = $this->taxpayerViewer();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'NAMRA_COMPLIANCE_OFFICER', 'scope_level' => 'NATIONAL',
            ]);

        $response->assertRedirect('/access-rights');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'NAMRA_COMPLIANCE_OFFICER']);
        $this->assertDatabaseHas('user_role_scope_grants', ['user_id' => $target->id, 'granted_by' => $admin->id]);
    }

    public function test_a_super_admin_cannot_grant_themselves_an_access_right(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $admin->id, 'role_code' => 'SECURITY_ANALYST', 'scope_level' => 'GLOBAL',
            ]);

        $response->assertRedirect('/access-rights');
        $response->assertSessionHasErrors('user_id');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'SUPER_ADMIN']);
    }

    public function test_granting_requires_access_rights_manage(): void
    {
        // access-rights:read alone doesn't exist as a role fixture yet, so
        // prove the gate directly against a role with neither permission.
        $developer = $this->developerPartner();
        $target = $this->taxpayerViewer();

        $this->actingAs($developer)->withSession(['auth.password_confirmed_at' => time()])->post('/access-rights', [
            'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
        ])->assertForbidden();
    }

    public function test_a_super_admin_can_revoke_an_active_grant_without_reverting_the_users_role(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
            ]);
        $grantId = DB::table('user_role_scope_grants')->where('user_id', $target->id)->value('id');

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/access-rights/{$grantId}/revoke");

        $response->assertRedirect('/access-rights');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('user_role_scope_grants', ['id' => $grantId, 'status' => 'REVOKED', 'revoked_by' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'TAXPAYER_ADMIN']);
    }

    public function test_revoking_an_already_revoked_grant_is_refused(): void
    {
        $admin = $this->superAdmin();
        $target = $this->taxpayerViewer();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/access-rights', [
                'user_id' => $target->id, 'role_code' => 'TAXPAYER_ADMIN', 'scope_level' => 'GLOBAL',
            ]);
        $grantId = DB::table('user_role_scope_grants')->where('user_id', $target->id)->value('id');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post("/access-rights/{$grantId}/revoke");

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/access-rights/{$grantId}/revoke");

        $response->assertRedirect('/access-rights');
        $response->assertSessionHasErrors('revoke');
    }
}

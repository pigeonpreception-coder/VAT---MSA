<?php

namespace Tests\Feature\Identity;

use App\Models\IdentityLink;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers Module 1's ResolveIdentity/LinkIdentity/RevokeSession
 * (App\Services\Identity\IdentityLinkService, ported from
 * lib/data/identity-repository.ts's listIdentityLinks/linkIdentity/
 * revokeIdentityLink) -- see the service's own doc comment for why this is
 * administrative bookkeeping/audit in this port rather than a command with
 * a real session-invalidation effect (Laravel's own auth guard never
 * consults identity_links, unlike source's header-trust model).
 */
class IdentityLinkTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IdentityProviderSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, admin: User, staff: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@linktest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@linktest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Staff", 'email' => strtolower($vatNumber).'-staff@linktest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin', 'staff');
    }

    private function nationalAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'National Admin', 'email' => 'national-admin@linktest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/identity/links')->assertUnauthorized();
        $this->postJson('/api/v1/identity/links')->assertUnauthorized();
        $this->postJson('/api/v1/identity/links/some-id/revocation')->assertUnauthorized();
    }

    public function test_a_user_can_list_their_own_identity_links(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0001');

        $response = $this->actingAs($org['staff'])->getJson('/api/v1/identity/links');

        $response->assertOk()->assertJsonPath('user_id', $org['staff']->id)->assertJsonPath('links', []);
    }

    public function test_a_non_admin_cannot_list_another_users_links(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0002');

        $response = $this->actingAs($org['staff'])->getJson("/api/v1/identity/links?user_id={$org['admin']->id}");

        $response->assertForbidden();
    }

    public function test_an_admin_can_list_another_users_links_in_their_own_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0003');

        $response = $this->actingAs($org['admin'])->getJson("/api/v1/identity/links?user_id={$org['staff']->id}");

        $response->assertOk()->assertJsonPath('user_id', $org['staff']->id);
    }

    public function test_link_without_a_fresh_step_up_is_locked(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0004');

        $response = $this->actingAs($org['admin'])->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'external-subject-1',
        ]);

        $response->assertStatus(423);
        $this->assertDatabaseCount('identity_links', 0);
    }

    public function test_an_admin_can_link_an_identity_to_a_user_in_their_own_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0005');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'sites_workspace', 'subject' => 'external-subject-2',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('link.provider_key', 'SITES_WORKSPACE')
            ->assertJsonPath('link.assurance_level', 'ADMINISTRATIVE_LINK')
            ->assertJsonPath('link.status', 'ACTIVE');
        $this->assertDatabaseHas('identity_links', ['user_id' => $org['staff']->id, 'subject' => 'external-subject-2', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_events', ['action' => 'IDENTITY_LINKED']);
    }

    public function test_a_national_admin_can_link_an_identity_for_a_user_in_any_taxpayer(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0006');
        $national = $this->nationalAdmin();

        $response = $this->actingAs($national)->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'external-subject-3',
        ]);

        $response->assertStatus(201);
    }

    public function test_an_admin_cannot_link_an_identity_for_a_user_outside_their_taxpayer_scope(): void
    {
        $orgA = $this->makeOrganisation('VAT-LNK-0007');
        $orgB = $this->makeOrganisation('VAT-LNK-0008');

        $response = $this->actingAs($orgA['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $orgB['staff']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'external-subject-4',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('identity_links', 0);
    }

    public function test_linking_against_a_not_yet_configured_provider_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0009');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'ITAS', 'subject' => 'external-subject-5',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'PROVIDER_NOT_CONFIGURED');
        $this->assertDatabaseCount('identity_links', 0);
    }

    public function test_linking_an_already_linked_subject_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0010');
        $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'shared-subject',
        ]);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['admin']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'shared-subject',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, IdentityLink::where('subject', 'shared-subject')->count());
    }

    public function test_linking_to_a_suspended_user_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0011');
        $org['staff']->update(['status' => 'SUSPENDED']);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links', [
            'user_id' => $org['staff']->id, 'provider_key' => 'SITES_WORKSPACE', 'subject' => 'external-subject-6',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'USER_NOT_ACTIVE');
    }

    public function test_revoke_without_a_fresh_step_up_is_locked(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0012');
        $link = IdentityLink::create([
            'user_id' => $org['staff']->id, 'provider_id' => \App\Models\IdentityProvider::where('provider_key', 'SITES_WORKSPACE')->firstOrFail()->id,
            'subject' => 'pre-existing-subject', 'email_at_link' => null, 'assurance_level' => 'ADMINISTRATIVE_LINK',
            'status' => 'ACTIVE', 'linked_at' => now(), 'last_authenticated_at' => null,
        ]);

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/identity/links/{$link->id}/revocation");

        $response->assertStatus(423);
        $this->assertDatabaseHas('identity_links', ['id' => $link->id, 'status' => 'ACTIVE']);
    }

    public function test_an_admin_can_revoke_an_identity_link_in_their_own_organisation(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0013');
        $link = IdentityLink::create([
            'user_id' => $org['staff']->id, 'provider_id' => \App\Models\IdentityProvider::where('provider_key', 'SITES_WORKSPACE')->firstOrFail()->id,
            'subject' => 'to-be-revoked', 'email_at_link' => null, 'assurance_level' => 'ADMINISTRATIVE_LINK',
            'status' => 'ACTIVE', 'linked_at' => now(), 'last_authenticated_at' => null,
        ]);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/identity/links/{$link->id}/revocation");

        $response->assertOk()->assertJsonPath('revocation.status', 'REVOKED');
        $this->assertDatabaseHas('identity_links', ['id' => $link->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'SESSION_REVOKED']);
    }

    public function test_revoking_an_already_revoked_link_is_an_idempotent_no_op(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0014');
        $link = IdentityLink::create([
            'user_id' => $org['staff']->id, 'provider_id' => \App\Models\IdentityProvider::where('provider_key', 'SITES_WORKSPACE')->firstOrFail()->id,
            'subject' => 'already-revoked', 'email_at_link' => null, 'assurance_level' => 'ADMINISTRATIVE_LINK',
            'status' => 'REVOKED', 'linked_at' => now(), 'last_authenticated_at' => null,
        ]);

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson("/api/v1/identity/links/{$link->id}/revocation");

        $response->assertOk()->assertJsonPath('revocation.status', 'REVOKED');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoking_a_link_outside_the_actors_taxpayer_scope_is_denied(): void
    {
        $orgA = $this->makeOrganisation('VAT-LNK-0015');
        $orgB = $this->makeOrganisation('VAT-LNK-0016');
        $link = IdentityLink::create([
            'user_id' => $orgB['staff']->id, 'provider_id' => \App\Models\IdentityProvider::where('provider_key', 'SITES_WORKSPACE')->firstOrFail()->id,
            'subject' => 'cross-tenant-subject', 'email_at_link' => null, 'assurance_level' => 'ADMINISTRATIVE_LINK',
            'status' => 'ACTIVE', 'linked_at' => now(), 'last_authenticated_at' => null,
        ]);

        $response = $this->actingAs($orgA['admin'])->withFreshStepUp()->postJson("/api/v1/identity/links/{$link->id}/revocation");

        $response->assertForbidden();
        $this->assertDatabaseHas('identity_links', ['id' => $link->id, 'status' => 'ACTIVE']);
    }

    public function test_revoking_a_nonexistent_link_returns_a_validation_error(): void
    {
        $org = $this->makeOrganisation('VAT-LNK-0017');

        $response = $this->actingAs($org['admin'])->withFreshStepUp()->postJson('/api/v1/identity/links/'.((string) Str::uuid()).'/revocation');

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'IDENTITY_LINK_NOT_FOUND');
    }
}

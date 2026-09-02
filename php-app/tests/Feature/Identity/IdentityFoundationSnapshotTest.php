<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Identity\IdentityFoundationSnapshotService (ported
 * from lib/data/identity-repository.ts's getIdentityFoundationSnapshot)
 * -- Phase 8's own last deferred piece.
 */
class IdentityFoundationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IdentityProviderSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, branch: Branch} */
    private function makeTaxpayerWithOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $branch = Branch::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'HEAD', 'name' => 'Head Office',
            'address' => '1 Test Street, Windhoek', 'status' => 'ACTIVE', 'is_head_office' => true,
        ]);
        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $owner->id,
            'role_code' => 'TAXPAYER_OWNER', 'branch_id' => $branch->id, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $owner->id, 'created_at' => now(),
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'branch');
    }

    public function test_a_taxpayer_scoped_actor_sees_only_their_own_organisation_registrations_and_access_counts(): void
    {
        $a = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0001');
        $b = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0002');

        $registration = $this->actingAs($a['owner'])->postJson('/api/v1/registration-applications', [
            'vat_number' => 'VAT-IDFOUND-NEW-0001', 'tin' => 'TIN-IDFOUND-NEW-0001', 'legal_name' => 'Another Branch of A Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY', 'address' => '2 New Street, Windhoek', 'email' => 'finance-a@test.test',
        ], ['Idempotency-Key' => 'idfound-test-key-000001']);
        $registration->assertStatus(202);

        $response = $this->actingAs($a['owner'])->getJson('/api/v1/identity');
        $response->assertStatus(200);

        $organisationIds = collect($response->json('organisations'))->pluck('id')->all();
        $this->assertContains($a['organisation']->id, $organisationIds);
        $this->assertNotContains($b['organisation']->id, $organisationIds);

        $registrationVatNumbers = collect($response->json('registrations'))->pluck('vat_number')->all();
        $this->assertContains('VAT-IDFOUND-NEW-0001', $registrationVatNumbers);

        // Exactly one branch and one membership -- A's own, not B's, proving
        // the taxpayer-scoped access counts are genuinely isolated, not a
        // platform-wide total that happens to be non-zero.
        $response->assertJsonPath('access.active_branches', 1)->assertJsonPath('access.active_memberships', 1);
    }

    public function test_a_national_scope_actor_sees_platform_wide_organisations_and_access_counts(): void
    {
        $a = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0003');
        $b = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0004');
        $nationalAdmin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'National Admin', 'email' => 'national-idfound-0003@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($nationalAdmin)->getJson('/api/v1/identity');
        $response->assertStatus(200);

        $organisationIds = collect($response->json('organisations'))->pluck('id')->all();
        $this->assertContains($a['organisation']->id, $organisationIds);
        $this->assertContains($b['organisation']->id, $organisationIds);

        // Both branches and both memberships are visible platform-wide.
        $this->assertGreaterThanOrEqual(2, $response->json('access.active_branches'));
        $this->assertGreaterThanOrEqual(2, $response->json('access.active_memberships'));
    }

    public function test_identity_providers_are_returned_in_the_sources_own_priority_ordering(): void
    {
        $ctx = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0005');

        $response = $this->actingAs($ctx['owner'])->getJson('/api/v1/identity');
        $response->assertStatus(200);

        $providerKeys = collect($response->json('providers'))->pluck('provider_key')->all();
        $this->assertSame(['ITAS', 'SITES_WORKSPACE', 'VAT_MSA_STANDALONE'], $providerKeys);
    }

    public function test_the_itas_integration_status_and_a_role_without_identity_read_is_denied(): void
    {
        $ctx = $this->makeTaxpayerWithOrganisation('VAT-IDFOUND-0006');

        $response = $this->actingAs($ctx['owner'])->getJson('/api/v1/identity');
        $response->assertStatus(200)->assertJsonPath('itas.provider', 'ITAS')->assertJsonPath('itas.configured', false);

        // SECURITY_ANALYST is a real, narrow national/platform role that
        // genuinely lacks identity:read (confirmed against
        // Permissions::ROLE_PERMISSIONS directly, not assumed).
        $analyst = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Analyst', 'email' => 'analyst-idfound-0006@test.test',
            'password' => bcrypt('password'), 'role' => 'SECURITY_ANALYST', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($analyst)->getJson('/api/v1/identity')->assertStatus(403);
    }
}

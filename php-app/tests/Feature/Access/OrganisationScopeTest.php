<?php

namespace Tests\Feature\Access;

use App\Models\BusinessParty;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Models\Scopes\OrganisationScope / App\Models\Concerns\
 * BelongsToOrganisation directly -- Phase 7's reusable Eloquent
 * organisation-scope trait, piloted on App\Models\BusinessParty (see that
 * model's own doc comment). Every case here exercises the automatic
 * global scope with NO manual `->where('organisation_id', ...)` of its
 * own -- the thing App\Services\Business\BusinessPartyService's own tests
 * (BusinessPartyAndQuotationTest) cannot prove, since every one of that
 * service's queries already adds its own explicit filter.
 */
class OrganisationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, party: BusinessParty} */
    private function makeTenant(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => "{$vatNumber} Supplier",
            'source_system' => 'test', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('taxpayer', 'organisation', 'party');
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    public function test_an_unscoped_query_with_no_authenticated_actor_is_not_filtered(): void
    {
        $tenantA = $this->makeTenant('VAT-SCOPE-0001');
        $tenantB = $this->makeTenant('VAT-SCOPE-0002');

        // No actingAs() at all -- the shape every fixture-building test
        // (including this file's own setUp/makeTenant) already relies on
        // working exactly like this: seeders, artisan commands, and plain
        // Eloquent fixture creation must never be silently filtered.
        $all = BusinessParty::all();

        $this->assertTrue($all->contains('id', $tenantA['party']->id));
        $this->assertTrue($all->contains('id', $tenantB['party']->id));
    }

    public function test_a_taxpayer_scoped_actor_only_sees_their_own_organisations_rows(): void
    {
        $tenantA = $this->makeTenant('VAT-SCOPE-0003');
        $tenantB = $this->makeTenant('VAT-SCOPE-0004');
        $ownerA = $this->taxpayerOwner($tenantA['taxpayer']->id, 'owner-a@scope.test');

        // actingAs() alone is enough to set the resolved auth guard for
        // direct Eloquent calls in-process; no HTTP request is needed.
        $this->actingAs($ownerA);
        $all = BusinessParty::all();

        $this->assertTrue($all->contains('id', $tenantA['party']->id));
        $this->assertFalse($all->contains('id', $tenantB['party']->id));
        $this->assertCount(1, $all);
    }

    public function test_a_national_scope_actor_sees_every_organisations_rows(): void
    {
        $tenantA = $this->makeTenant('VAT-SCOPE-0005');
        $tenantB = $this->makeTenant('VAT-SCOPE-0006');
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'admin@scope.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($admin);
        $all = BusinessParty::all();

        $this->assertTrue($all->contains('id', $tenantA['party']->id));
        $this->assertTrue($all->contains('id', $tenantB['party']->id));
    }

    public function test_withoutorganisationscope_bypasses_the_filter_on_demand(): void
    {
        $tenantA = $this->makeTenant('VAT-SCOPE-0007');
        $tenantB = $this->makeTenant('VAT-SCOPE-0008');
        $ownerA = $this->taxpayerOwner($tenantA['taxpayer']->id, 'owner-b@scope.test');

        $this->actingAs($ownerA);
        $scoped = BusinessParty::all();
        $unscoped = BusinessParty::withoutOrganisationScope()->get();

        $this->assertFalse($scoped->contains('id', $tenantB['party']->id));
        $this->assertTrue($unscoped->contains('id', $tenantA['party']->id));
        $this->assertTrue($unscoped->contains('id', $tenantB['party']->id));
    }

    public function test_an_actor_with_neither_national_scope_nor_a_taxpayer_sees_nothing(): void
    {
        $this->makeTenant('VAT-SCOPE-0009');
        // SUPER_ADMIN is taxpayer_id=null but not in Permissions::NATIONAL_SCOPE_ROLES
        // (it is a platform-technical role, not a tax-administration one) --
        // the scope must not mistake "no taxpayer" for "sees everything".
        $superAdmin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => 'super@scope.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($superAdmin);
        $all = BusinessParty::all();

        $this->assertCount(0, $all);
    }
}

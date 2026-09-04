<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\PartyRelationship;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI bundling BusinessPartyService with
 * SupplierVerificationService -- App\Http\Controllers\Business\
 * BusinessPartyViewController / resources/views/business-parties/** -- the
 * frontend UI build-out's tenth slice, the fourth fresh, smaller PR (after
 * Disputes, Obligations, Organisations & Identity). Reuses
 * SupplierVerificationTest's and BusinessPartyAndQuotationTest's own
 * makeOrganisation fixture pattern.
 */
class BusinessPartyViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeOrganisation(string $vatNumber, array $capabilities = ['BUYER', 'SELLER']): array
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /** @return array{party: BusinessParty} */
    private function makeParty(Organisation $organisation, string $vatNumber, array $relationships, string $status = 'ACTIVE'): BusinessParty
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => 'Counterparty Co',
            'legal_name' => 'Counterparty Co (Pty) Ltd', 'vat_number' => $vatNumber ?: null, 'tin' => null, 'email' => 'cp@test.test',
            'phone' => null, 'address' => null, 'source_system' => 'LOCAL', 'source_party_id' => null, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($relationships as $relationship) {
            PartyRelationship::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
                'relationship' => $relationship, 'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'created_at' => now(),
            ]);
        }

        return $party;
    }

    /** Holds commercial:read but not parties:manage -- the read-only fixture. */
    private function sellerViewer(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_parties_list_requires_authentication(): void
    {
        $this->get('/business-parties')->assertRedirect('/login');
    }

    public function test_a_role_without_the_manage_permission_is_forbidden_everywhere(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0001');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0001S', ['SUPPLIER']);
        $viewer = $this->sellerViewer($org['taxpayer']->id);

        $this->actingAs($viewer)->get('/business-parties')->assertForbidden();
        $this->actingAs($viewer)->get(route('business-parties.show', $party->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('business-parties.store'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('business-parties.verification.store', $party->id))->assertForbidden();
    }

    public function test_registering_a_party_with_a_relationship_creates_a_real_row(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0002');

        $response = $this->actingAs($org['owner'])->post(route('business-parties.store'), [
            'display_name' => 'Acme Supplier', 'legal_name' => 'Acme Supplier (Pty) Ltd',
            'vat_number' => 'VAT-VIEW-BP-0002S', 'email' => 'ap@acme.test', 'relationships' => ['SUPPLIER'],
        ]);

        $party = BusinessParty::where('organisation_id', $org['organisation']->id)->where('display_name', 'Acme Supplier')->firstOrFail();
        $response->assertRedirect(route('business-parties.show', $party->id));
        $this->assertSame('ACTIVE', $party->status);
        $this->assertContains('SUPPLIER', $party->relationships()->pluck('relationship')->all());
    }

    public function test_registering_a_party_with_no_relationship_selected_is_a_friendly_field_error(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0003');

        $response = $this->actingAs($org['owner'])->post(route('business-parties.store'), [
            'display_name' => 'No Relationship Co',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('relationships');
        $this->assertDatabaseCount('business_parties', 0);
    }

    public function test_registering_a_party_with_a_duplicate_vat_number_is_a_friendly_form_error(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0004');
        $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0004D', ['CUSTOMER']);

        $response = $this->actingAs($org['owner'])->post(route('business-parties.store'), [
            'display_name' => 'Duplicate VAT Co', 'vat_number' => 'VAT-VIEW-BP-0004D', 'relationships' => ['CUSTOMER'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertSame(1, BusinessParty::where('organisation_id', $org['organisation']->id)->count());
    }

    public function test_the_show_page_renders_contact_details_and_an_empty_verification_history(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0005');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0005S', ['SUPPLIER']);

        $response = $this->actingAs($org['owner'])->get(route('business-parties.show', $party->id));

        $response->assertOk()->assertViewIs('business-parties.show');
        $response->assertSee('Not yet verified.');
        $response->assertSee('Verify against national taxpayer register');
    }

    public function test_a_customer_only_party_shows_a_disabled_verify_hint_instead_of_a_live_button(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0006');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0006C', ['CUSTOMER']);

        $response = $this->actingAs($org['owner'])->get(route('business-parties.show', $party->id));

        $response->assertOk();
        $response->assertSee('Only a supplier relationship can be verified.');
    }

    public function test_verifying_a_real_supplier_writes_a_snapshot_visible_in_the_history(): void
    {
        $this->makeOrganisation('VAT-VIEW-BP-0007S', ['SELLER']);
        $org = $this->makeOrganisation('VAT-VIEW-BP-0007');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0007S', ['SUPPLIER']);

        $response = $this->actingAs($org['owner'])->post(route('business-parties.verification.store', $party->id));

        $response->assertRedirect(route('business-parties.show', $party->id));
        $this->assertDatabaseHas('party_verification_snapshots', ['party_id' => $party->id, 'can_act_as_seller' => 1]);

        $show = $this->actingAs($org['owner'])->get(route('business-parties.show', $party->id));
        $show->assertDontSee('Not yet verified.');
        $show->assertSee('SELLER');
    }

    public function test_verifying_a_customer_only_party_is_a_friendly_form_error_not_a_raw_409(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0008');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0008C', ['CUSTOMER']);

        $response = $this->actingAs($org['owner'])->post(route('business-parties.verification.store', $party->id));

        $response->assertRedirect(route('business-parties.show', $party->id));
        $response->assertSessionHasErrors('form');
        $this->assertDatabaseCount('party_verification_snapshots', 0);
    }

    public function test_deactivating_an_active_party_flips_its_status_and_hides_the_card_afterward(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0009');
        $party = $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0009S', ['SUPPLIER']);

        $response = $this->actingAs($org['owner'])->post(route('business-parties.deactivation.store', $party->id), [
            'reason' => 'Supplier relationship ended.',
        ]);

        $response->assertRedirect(route('business-parties.show', $party->id));
        $this->assertSame('INACTIVE', $party->fresh()->status);

        $show = $this->actingAs($org['owner'])->get(route('business-parties.show', $party->id));
        $show->assertDontSee('Deactivate party');
    }

    public function test_a_taxpayer_cannot_view_another_taxpayers_business_party(): void
    {
        $orgA = $this->makeOrganisation('VAT-VIEW-BP-0010');
        $orgB = $this->makeOrganisation('VAT-VIEW-BP-0011');
        $partyB = $this->makeParty($orgB['organisation'], 'VAT-VIEW-BP-0011S', ['SUPPLIER']);

        $this->actingAs($orgA['owner'])->get(route('business-parties.show', $partyB->id))->assertNotFound();
    }

    public function test_the_list_page_filters_by_relationship(): void
    {
        $org = $this->makeOrganisation('VAT-VIEW-BP-0012');
        $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0012C', ['CUSTOMER']);
        $this->makeParty($org['organisation'], 'VAT-VIEW-BP-0012S', ['SUPPLIER']);

        $suppliers = $this->actingAs($org['owner'])->get(route('business-parties.index', ['relationship' => 'SUPPLIER']));
        $suppliers->assertOk();

        $customers = $this->actingAs($org['owner'])->get(route('business-parties.index', ['relationship' => 'CUSTOMER']));
        $customers->assertOk();
    }
}

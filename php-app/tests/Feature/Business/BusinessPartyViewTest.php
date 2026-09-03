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
 * Covers the real Blade UI for the customer/supplier directory
 * (App\Http\Controllers\Business\BusinessPartyViewController /
 * resources/views/parties/index.blade.php) -- ported from the source's own
 * app/commercial/parties/page.tsx + PartyManager.tsx. Reuses
 * App\Services\Business\BusinessPartyService directly (already covered end
 * to end by tests/Feature/Business/BusinessPartyAndQuotationTest.php), so
 * this file's own job is proving the page's access gate and its
 * server-rendered create/edit/deactivate form flows.
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
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@partiesview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeParty(Organisation $organisation, array $overrides = []): BusinessParty
    {
        $party = BusinessParty::create(array_replace([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => 'Existing Customer',
            'legal_name' => 'Existing Customer (Pty) Ltd', 'vat_number' => 'VAT-EXIST-0001', 'status' => 'ACTIVE',
            'source_system' => 'LOCAL', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'CUSTOMER', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_at' => now(),
        ]);

        return $party;
    }

    public function test_the_parties_page_requires_authentication(): void
    {
        $this->get('/parties')->assertRedirect('/login');
    }

    public function test_a_role_without_parties_manage_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-DENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@partiesview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/parties')->assertForbidden();
    }

    public function test_the_parties_page_renders_the_register_and_create_form(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $this->makeParty($seller['organisation']);

        $response = $this->actingAs($seller['owner'])->get('/parties');

        $response->assertOk()->assertViewIs('parties.index');
        $response->assertSee('Existing Customer');
        $response->assertSee('Register a business party');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_a_business_party_can_be_created_through_the_form(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0002');

        $response = $this->actingAs($seller['owner'])->post('/parties', [
            'display_name' => 'New Form Customer', 'legal_name' => 'New Form Customer (Pty) Ltd',
            'vat_number' => 'VAT-FORM-0001', 'email' => 'form@newcustomer.test', 'relationships' => ['CUSTOMER'],
        ]);

        $response->assertRedirect('/parties');
        $response->assertSessionHas('status', 'Business party created.');
        $this->assertDatabaseHas('business_parties', [
            'display_name' => 'New Form Customer', 'organisation_id' => $seller['organisation']->id, 'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'BUSINESS_PARTY_CREATED']);
    }

    public function test_creating_a_party_without_a_relationship_fails_validation_and_keeps_input(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0003');

        $response = $this->actingAs($seller['owner'])->post('/parties', [
            'display_name' => 'No Relationship Co', 'relationships' => [],
        ]);

        $response->assertRedirect('/parties');
        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('business_parties', ['display_name' => 'No Relationship Co']);
    }

    public function test_a_duplicate_vat_number_surfaces_as_a_form_error_not_a_500(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0004');
        $this->makeParty($seller['organisation'], ['vat_number' => 'VAT-DUPE-0001']);

        $response = $this->actingAs($seller['owner'])->post('/parties', [
            'display_name' => 'Duplicate VAT Co', 'vat_number' => 'VAT-DUPE-0001', 'relationships' => ['SUPPLIER'],
        ]);

        $response->assertRedirect('/parties');
        $response->assertSessionHasErrors('party');
        $this->assertDatabaseMissing('business_parties', ['display_name' => 'Duplicate VAT Co']);
    }

    public function test_the_edit_form_is_prefilled_from_the_query_parameter(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0005');
        $party = $this->makeParty($seller['organisation'], ['display_name' => 'Edit Target Co']);

        $response = $this->actingAs($seller['owner'])->get('/parties?edit='.$party->id);

        $response->assertOk();
        $response->assertSee('Edit business party');
        $response->assertViewHas('editing', fn ($editing) => $editing['display_name'] === 'Edit Target Co');
    }

    public function test_an_active_party_can_be_updated_through_the_form(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0006');
        $party = $this->makeParty($seller['organisation']);

        $response = $this->actingAs($seller['owner'])->patch("/parties/{$party->id}", [
            'display_name' => 'Renamed Customer', 'relationships' => ['CUSTOMER', 'SUPPLIER'],
        ]);

        $response->assertRedirect('/parties');
        $response->assertSessionHas('status', 'Business party updated.');
        $this->assertDatabaseHas('business_parties', ['id' => $party->id, 'display_name' => 'Renamed Customer']);
        $this->assertDatabaseHas('party_relationships', ['party_id' => $party->id, 'relationship' => 'SUPPLIER', 'status' => 'ACTIVE']);
    }

    public function test_a_party_can_be_deactivated_through_the_form_with_a_reason(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0007');
        $party = $this->makeParty($seller['organisation']);

        $response = $this->actingAs($seller['owner'])->post("/parties/{$party->id}/deactivation", [
            'reason' => 'Trading relationship ended by mutual agreement.',
        ]);

        $response->assertRedirect('/parties');
        $response->assertSessionHas('status', 'Business party deactivated.');
        $this->assertDatabaseHas('business_parties', ['id' => $party->id, 'status' => 'INACTIVE']);
        $this->assertDatabaseHas('audit_events', ['action' => 'BUSINESS_PARTY_DEACTIVATED']);
    }

    public function test_deactivating_with_too_short_a_reason_fails_validation(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0008');
        $party = $this->makeParty($seller['organisation']);

        $response = $this->actingAs($seller['owner'])->post("/parties/{$party->id}/deactivation", ['reason' => 'no']);

        $response->assertRedirect('/parties');
        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('business_parties', ['id' => $party->id, 'status' => 'ACTIVE']);
    }
}

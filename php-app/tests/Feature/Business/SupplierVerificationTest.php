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
 * Covers App\Services\Business\SupplierVerificationService (ported from
 * lib/data/business-repository.ts's verifySupplier/
 * getSupplierVerificationHistory, Module 5 Phase A) -- the last function
 * Phase 10 (accounting/commercial) deferred. Also exercises
 * App\Support\Business\TransactionClassifier, pulled out of the still-
 * unported lib/data/identity-repository.ts as the one function this needs.
 */
class SupplierVerificationTest extends TestCase
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

    /** @return array{party: BusinessParty, org: array{taxpayer: Taxpayer, organisation: Organisation, owner: User}} */
    private function makePartyWithRelationship(string $partyVatNumber, string $relationship): array
    {
        $org = $this->makeOrganisation('VAT-OWNER-'.Str::random(6));
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'display_name' => 'Counterparty Co',
            'legal_name' => 'Counterparty Co (Pty) Ltd', 'vat_number' => $partyVatNumber, 'tin' => null, 'email' => 'cp@test.test',
            'phone' => null, 'address' => null, 'source_system' => 'LOCAL', 'source_party_id' => null, 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($relationship) {
            PartyRelationship::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'party_id' => $party->id,
                'relationship' => $relationship, 'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'created_at' => now(),
            ]);
        }

        return ['party' => $party, 'org' => $org];
    }

    public function test_verifying_an_active_registered_supplier_writes_a_snapshot_and_history_reads_it_back(): void
    {
        $counterparty = $this->makeOrganisation('VAT-REAL-SUP-0001', ['SELLER']);
        $ctx = $this->makePartyWithRelationship('VAT-REAL-SUP-0001', 'SUPPLIER');

        $verify = $this->actingAs($ctx['org']['owner'])->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)]);
        $verify->assertStatus(200)
            ->assertJsonPath('resource.vat_number', 'VAT-REAL-SUP-0001')
            ->assertJsonPath('resource.taxpayer_active', true)
            ->assertJsonPath('resource.organisation_active', true)
            ->assertJsonPath('resource.can_act_as_seller', true)
            ->assertJsonPath('resource.capabilities', ['SELLER']);
        $this->assertDatabaseHas('party_verification_snapshots', ['party_id' => $ctx['party']->id, 'can_act_as_seller' => 1]);
        $this->assertDatabaseHas('audit_events', ['action' => 'SUPPLIER_VERIFIED', 'resource_id' => $ctx['party']->id]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SupplierVerified']);

        $history = $this->actingAs($ctx['org']['owner'])->getJson("/api/v1/business-parties/{$ctx['party']->id}/verification");
        $history->assertStatus(200)->assertJsonCount(1, 'snapshots')->assertJsonPath('party.id', $ctx['party']->id);
    }

    public function test_verifying_an_unregistered_vat_number_reports_every_flag_false(): void
    {
        $ctx = $this->makePartyWithRelationship('VAT-UNKNOWN-9999', 'SUPPLIER');

        $verify = $this->actingAs($ctx['org']['owner'])->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)]);
        $verify->assertStatus(200)
            ->assertJsonPath('resource.taxpayer_active', false)
            ->assertJsonPath('resource.organisation_active', false)
            ->assertJsonPath('resource.can_act_as_seller', false)
            ->assertJsonPath('resource.capabilities', []);
    }

    public function test_a_party_without_an_active_supplier_relationship_or_a_vat_number_cannot_be_verified(): void
    {
        $customerOnly = $this->makePartyWithRelationship('VAT-CUST-0001', 'CUSTOMER');
        $this->actingAs($customerOnly['org']['owner'])
            ->postJson("/api/v1/business-parties/{$customerOnly['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)])
            ->assertStatus(409);

        $noVatNumber = $this->makePartyWithRelationship('', 'SUPPLIER');
        BusinessParty::where('id', $noVatNumber['party']->id)->update(['vat_number' => null]);
        $this->actingAs($noVatNumber['org']['owner'])
            ->postJson("/api/v1/business-parties/{$noVatNumber['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)])
            ->assertStatus(409);
    }

    public function test_verification_always_writes_a_fresh_snapshot_even_on_idempotent_replay(): void
    {
        $this->makeOrganisation('VAT-REAL-SUP-0002', ['SELLER']);
        $ctx = $this->makePartyWithRelationship('VAT-REAL-SUP-0002', 'SUPPLIER');
        $key = 'verify-shared-'.Str::random(20);

        $first = $this->actingAs($ctx['org']['owner'])->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $second = $this->actingAs($ctx['org']['owner'])->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => $key]);
        $second->assertStatus(200);

        $this->assertNotSame($first->json('resource.id'), $second->json('resource.id'));
        $this->assertDatabaseCount('party_verification_snapshots', 2);
        // Only the first call's audit/outbox pair is written -- the replay is not re-audited.
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('outbox_events', 1);

        $history = $this->actingAs($ctx['org']['owner'])->getJson("/api/v1/business-parties/{$ctx['party']->id}/verification");
        $history->assertStatus(200)->assertJsonCount(2, 'snapshots');
    }

    public function test_verification_requires_permission_and_is_scoped_to_the_owning_organisation(): void
    {
        $ctx = $this->makePartyWithRelationship('VAT-REAL-SUP-0003', 'SUPPLIER');
        $stranger = $this->makeOrganisation('VAT-STRANGER-0001');

        $this->actingAs($stranger['owner'])
            ->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)])
            ->assertStatus(404);
        $this->actingAs($stranger['owner'])
            ->getJson("/api/v1/business-parties/{$ctx['party']->id}/verification")
            ->assertStatus(404);

        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $ctx['org']['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($viewer)
            ->postJson("/api/v1/business-parties/{$ctx['party']->id}/verification", [], ['Idempotency-Key' => 'verify-'.Str::random(20)])
            ->assertStatus(403);
    }
}

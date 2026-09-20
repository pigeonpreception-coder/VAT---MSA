<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 10 (slice 1): business parties (App\Services\Business\
 * BusinessPartyService, ported from createBusinessParty/updateBusinessParty/
 * deactivateBusinessParty/searchBusinessParties) and quotations
 * (App\Services\Business\QuotationService, ported from createQuotation
 * through convertQuotationToInvoice) -- Module 5 Phases A and B. The
 * quotation->invoice conversion test is the one genuine cross-module
 * integration point: it exercises Phase 9's InvoiceService::submit for real,
 * not a stub.
 */
class BusinessPartyAndQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function partyPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'display_name' => 'Acme Customer', 'legal_name' => 'Acme Customer (Pty) Ltd',
            'vat_number' => 'VAT-PARTY-0001', 'email' => 'ap@acme.test', 'relationships' => ['CUSTOMER'],
        ], $overrides);
    }

    public function test_a_business_party_can_be_created_with_relationships(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $this->partyPayload(), ['Idempotency-Key' => 'test-idem-party-0001-aaaa']);

        $response->assertStatus(201)->assertJsonPath('resource.display_name', 'Acme Customer')->assertJsonPath('resource.relationships', ['CUSTOMER']);
        $this->assertDatabaseHas('business_parties', ['display_name' => 'Acme Customer', 'organisation_id' => $seller['organisation']->id]);
        $this->assertDatabaseHas('party_relationships', ['relationship' => 'CUSTOMER', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_events', ['action' => 'BUSINESS_PARTY_CREATED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'BusinessPartyCreated']);
    }

    public function test_creating_a_party_with_a_duplicate_vat_number_is_a_conflict(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $this->partyPayload(), ['Idempotency-Key' => 'test-idem-party-dup-0001'])->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $this->partyPayload(['display_name' => 'Acme Duplicate']), ['Idempotency-Key' => 'test-idem-party-dup-0002']);

        $response->assertStatus(409);
    }

    public function test_replaying_the_same_idempotency_key_and_payload_returns_the_identical_party(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $payload = $this->partyPayload();

        $first = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $payload, ['Idempotency-Key' => 'test-idem-party-replay-0001']);
        $second = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $payload, ['Idempotency-Key' => 'test-idem-party-replay-0001']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('resource.id'), $second->json('resource.id'));
        $this->assertDatabaseCount('business_parties', 1);
    }

    public function test_a_party_can_be_updated_and_then_deactivated(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $create = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $this->partyPayload(), ['Idempotency-Key' => 'test-idem-party-upd-0001']);
        $partyId = $create->json('resource.id');

        $update = $this->actingAs($seller['owner'])->patchJson("/api/v1/business-parties/{$partyId}", $this->partyPayload(['display_name' => 'Acme Renamed', 'relationships' => ['CUSTOMER', 'SUPPLIER']]), ['Idempotency-Key' => 'test-idem-party-upd-0002']);
        $update->assertStatus(200)->assertJsonPath('resource.display_name', 'Acme Renamed');
        $this->assertEqualsCanonicalizing(['CUSTOMER', 'SUPPLIER'], $update->json('resource.relationships'));

        $deactivate = $this->actingAs($seller['owner'])->postJson("/api/v1/business-parties/{$partyId}/deactivation", ['schema_version' => '1.0.0', 'reason' => 'No longer trading with this customer.'], ['Idempotency-Key' => 'test-idem-party-deact-0001']);
        $deactivate->assertStatus(200)->assertJsonPath('resource.status', 'INACTIVE');

        $editAfterDeactivate = $this->actingAs($seller['owner'])->patchJson("/api/v1/business-parties/{$partyId}", $this->partyPayload(['display_name' => 'Should Fail']), ['Idempotency-Key' => 'test-idem-party-upd-0003']);
        $editAfterDeactivate->assertStatus(409);
    }

    public function test_a_viewer_without_parties_manage_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/business-parties', $this->partyPayload(), ['Idempotency-Key' => 'test-idem-party-viewer-0001']);

        $response->assertStatus(403);
    }

    private function quotationPayload(string $customerPartyId, array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'customer_party_id' => $customerPartyId, 'quotation_number' => 'QUO-TEST-0001',
            'currency' => 'NAD', 'issue_date' => '2026-09-01', 'valid_until' => '2026-09-30',
            'lines' => [['description' => 'Consulting services', 'quantity_micros' => 1_000_000, 'unit_code' => 'EA', 'unit_price_cents' => 100_000, 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500]],
        ], $overrides);
    }

    private function createCustomerParty(User $owner, string $vatNumber = 'VAT-CUST-0001'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/business-parties', $this->partyPayload(['vat_number' => $vatNumber, 'relationships' => ['CUSTOMER']]), ['Idempotency-Key' => 'test-idem-cust-'.$vatNumber]);

        return $response->json('resource.id');
    }

    public function test_a_quotation_can_be_created_sent_accepted_and_converted_to_a_real_invoice(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);

        $create = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-create-0001']);
        $create->assertStatus(201)->assertJsonPath('resource.status', 'DRAFT')->assertJsonPath('resource.total_cents', 115000);
        $quotationId = $create->json('resource.id');
        $this->assertDatabaseHas('quotation_revisions', ['quotation_id' => $quotationId, 'action' => 'CREATE', 'revision_number' => 1]);

        $send = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/sending", [], ['Idempotency-Key' => 'test-idem-quo-send-0001']);
        $send->assertStatus(200)->assertJsonPath('resource.status', 'ISSUED');

        $accept = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/accept", [], ['Idempotency-Key' => 'test-idem-quo-accept-0001']);
        $accept->assertStatus(200)->assertJsonPath('resource.status', 'ACCEPTED');

        $convert = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/convert", [
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-FROM-QUO-0001', 'issue_date' => '2026-09-02',
        ], ['Idempotency-Key' => 'test-idem-quo-convert-0001']);

        // CERTIFIED rather than MATCHED: the quotation's customer_party_id is a local
        // business_parties row (commercial-ledger concept), not itself a taxpayer/
        // organisation with a registered BUYER capability -- InvoiceService::submit's
        // buyer resolution (Phase 9) is correctly a separate, national-registry concern,
        // so this converted invoice takes the unregistered-buyer path exactly as it
        // would for any other invoice to a VAT number outside the pilot registry.
        $convert->assertStatus(201)->assertJsonPath('resource.status', 'CERTIFIED')->assertJsonPath('resource.totalCents', 115000);
        $invoiceId = $convert->json('resource.id');
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'invoice_number' => 'INV-FROM-QUO-0001', 'total_cents' => 115000]);
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'CONVERTED', 'converted_invoice_id' => $invoiceId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'QUOTATION_CONVERTED', 'resource_id' => $quotationId]);
    }

    /**
     * Broader security-sweep follow-up (2026-09-20): same shape and same
     * fix as PurchaseOrderService::convertToExpense()'s own race, fixed
     * the same day -- see that method's doc comment for the general
     * pattern. QuotationService::convertToInvoice()'s own pre-checks
     * (status, converted_invoice_id) used to be plain, unlocked reads,
     * and the real side effect -- a certified TAX_INVOICE via
     * InvoiceService::submit() -- ran before any guard on the quotation's
     * own status update. Two concurrent conversions of the same ACCEPTED
     * quotation could each certify their own real invoice, with the
     * loser's left orphaned (no quotation reference) while still
     * reporting success. Fixed by locking the quotation first and holding
     * that lock for the whole conversion. Reproduced with the same
     * `DB::listen()` same-connection race-simulation technique as the
     * purchase-order regression test.
     */
    public function test_a_quotation_that_races_a_concurrent_conversion_does_not_create_an_orphaned_duplicate_invoice(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $create = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-race-create-0001']);
        $quotationId = $create->json('resource.id');
        $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/sending", [], ['Idempotency-Key' => 'test-idem-quo-race-send-0001']);
        $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/accept", [], ['Idempotency-Key' => 'test-idem-quo-race-accept-0001']);

        $raced = false;
        DB::listen(function ($query) use (&$raced, $quotationId) {
            if ($raced || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $raced = true;
            // Models the concurrent winner's own conversion, already fully
            // committed (a real, separately certified invoice, not
            // modelled here since it isn't what this request's own code
            // reads) by the time this request's lock-acquiring SELECT runs.
            DB::table('quotations')->where('id', $quotationId)->update([
                'status' => 'CONVERTED', 'converted_invoice_id' => (string) Str::uuid(), 'updated_at' => now(),
            ]);
        });

        $convert = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/convert", [
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-RACE-LOSER-0001', 'issue_date' => '2026-09-02',
        ], ['Idempotency-Key' => 'test-idem-quo-race-convert-0001']);

        $this->assertTrue($raced, 'The DB::listen() hook must have fired to simulate the race (it never fires at all on the pre-fix code path, which has no lockForUpdate query).');
        $convert->assertStatus(409);
        // The would-be loser's own invoice must never have been created --
        // pre-fix, it would exist here as a real, orphaned, certified
        // invoice despite the request appearing to fail.
        $this->assertDatabaseMissing('invoices', ['invoice_number' => 'INV-RACE-LOSER-0001']);
    }

    public function test_a_draft_quotation_cannot_be_accepted_directly(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $create = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-noaccept-0001']);
        $quotationId = $create->json('resource.id');

        $accept = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/accept", [], ['Idempotency-Key' => 'test-idem-quo-noaccept-0002']);

        $accept->assertStatus(409);
    }

    public function test_a_quotation_referencing_a_party_without_the_customer_relationship_is_rejected(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $supplierOnly = $this->actingAs($seller['owner'])->postJson('/api/v1/business-parties', $this->partyPayload(['vat_number' => 'VAT-SUPPLIERONLY-0001', 'relationships' => ['SUPPLIER']]), ['Idempotency-Key' => 'test-idem-supplieronly-0001'])->json('resource.id');

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($supplierOnly), ['Idempotency-Key' => 'test-idem-quo-badparty-0001']);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('quotations', ['quotation_number' => 'QUO-TEST-0001']);
    }

    public function test_a_duplicate_quotation_number_is_rejected_as_a_conflict(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-dup-0001'])->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-dup-0002']);

        $response->assertStatus(409);
    }

    public function test_an_issued_quotation_can_be_rejected(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $quotationId = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-rej-0001'])->json('resource.id');
        $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/sending", [], ['Idempotency-Key' => 'test-idem-quo-rej-0002'])->assertStatus(200);

        $reject = $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/rejection", ['schema_version' => '1.0.0', 'reason' => 'Customer chose another vendor.'], ['Idempotency-Key' => 'test-idem-quo-rej-0003']);

        $reject->assertStatus(200)->assertJsonPath('resource.status', 'REJECTED');
    }

    public function test_an_issued_quotation_can_be_edited_and_a_draft_quotation_number_is_immutable(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $quotationId = $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-edit-0001'])->json('resource.id');
        $this->actingAs($seller['owner'])->postJson("/api/v1/quotations/{$quotationId}/sending", [], ['Idempotency-Key' => 'test-idem-quo-edit-0002'])->assertStatus(200);

        $edit = $this->actingAs($seller['owner'])->patchJson("/api/v1/quotations/{$quotationId}", $this->quotationPayload($customerPartyId, ['notes' => 'Revised pricing.']), ['Idempotency-Key' => 'test-idem-quo-edit-0003']);

        $edit->assertStatus(200)->assertJsonPath('resource.notes', 'Revised pricing.')->assertJsonPath('resource.status', 'ISSUED');
        $this->assertDatabaseHas('quotation_revisions', ['quotation_id' => $quotationId, 'action' => 'EDIT']);

        $immutableNumber = $this->actingAs($seller['owner'])->patchJson("/api/v1/quotations/{$quotationId}", $this->quotationPayload($customerPartyId, ['quotation_number' => 'QUO-DIFFERENT-0001']), ['Idempotency-Key' => 'test-idem-quo-edit-0004']);
        $immutableNumber->assertStatus(409);
    }

    public function test_quotation_search_filters_by_status_and_customer(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId), ['Idempotency-Key' => 'test-idem-quo-search-0001'])->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->getJson('/api/v1/quotations?status=DRAFT&customer_party_id='.$customerPartyId);

        $response->assertStatus(200)->assertJsonPath('total_count', 1)->assertJsonPath('quotations.0.quotation_number', 'QUO-TEST-0001');
    }

    /**
     * Red-team punch list #12 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): "Real query/N+1 performance at production-
     * representative data volumes was never tested" -- true against the
     * dev seed's near-empty tables, but a synthetic load seed
     * (database/seeders/SyntheticLoadSeeder.php, added the same day)
     * made two genuine N+1s concretely visible for the first time:
     * QuotationService::search() mapping every row through present()
     * (built for the single-record find() case), lazy-loading `customer`
     * and running a full QuotationLine query per row; and
     * BusinessPartyService::search() (reached here via the customer-
     * party lookups this test also exercises) running a PartyRelationship
     * query per row. Both are now batched. This asserts the fix holds at
     * a size where an unfixed N+1 would be unmistakable in the query
     * count (30 quotations, each with its own customer party and line).
     */
    public function test_quotation_search_does_not_n_plus_one_at_scale(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-NPLUS1');
        $rows = 30;

        for ($i = 0; $i < $rows; $i++) {
            $customerPartyId = $this->createCustomerParty($seller['owner'], sprintf('VAT-CUST-NPLUS1-%04d', $i));
            $this->actingAs($seller['owner'])
                ->postJson('/api/v1/quotations', $this->quotationPayload($customerPartyId, ['quotation_number' => "QUO-NPLUS1-{$i}"]), ['Idempotency-Key' => "test-idem-quo-nplus1-{$i}"])
                ->assertStatus(201);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($seller['owner'])->getJson('/api/v1/quotations?limit=100');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200)->assertJsonPath('total_count', $rows);
        $this->assertLessThan(20, $queryCount, "Expected a small, row-count-independent query count; got {$queryCount} for {$rows} quotation rows -- an N+1 regression scales with row count, not a fixed ceiling.");
    }
}

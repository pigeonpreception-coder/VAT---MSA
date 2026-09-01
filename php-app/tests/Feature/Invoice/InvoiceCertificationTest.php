<?php

namespace Tests\Feature\Invoice;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers InvoiceService::submit (ported from lib/data/repository.ts's
 * submitInvoice, Module 2 Phases A-E) over real HTTP against MySQL, mirroring
 * this session's own manual curl verification: VAT-rule resolution (fails
 * closed on no bound rule or a rate mismatch), supplier/customer resolution
 * via the dynamic organisation_capabilities grant, tenant-scope enforcement,
 * duplicate/collision detection, credit-note correction lineage with the
 * cumulative-credit cap, and idempotent replay (same key/payload returns the
 * identical response; same key/different payload conflicts).
 */
class InvoiceCertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, array $capabilities = ['BUYER', 'SELLER']): array
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

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0',
            'invoice_number' => 'INV-TEST-0001',
            'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-test-0001', 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SUP-0001']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUS-0001']]],
            'issue_date' => '2026-09-01',
            'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']],
            ],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    public function test_a_valid_invoice_is_certified_with_a_registered_buyer(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), [
            'Idempotency-Key' => 'test-idem-key-standard-0001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('processing_status', 'MATCHED')
            ->assertJsonPath('vat_rules_applied.0.tax_category', 'STANDARD')
            ->assertJsonPath('vat_rules_applied.0.vat_rule_version', 1);
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-TEST-0001', 'status' => 'MATCHED', 'total_cents' => 115000]);
        $this->assertDatabaseHas('ledger_entries', ['taxpayer_id' => $supplier['taxpayer']->id, 'entry_type' => 'OUTPUT_VAT', 'direction' => 'CREDIT', 'amount_cents' => 15000]);
        $this->assertDatabaseHas('audit_events', ['action' => 'INVOICE_CERTIFIED', 'resource_id' => $response->json('invoice_id')]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $response->json('invoice_id'), 'event_type' => 'InvoiceCertified']);
    }

    public function test_an_invoice_to_an_unregistered_buyer_is_still_certified_but_flagged(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');

        $response = $this->actingAs(User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail())
            ->postJson('/api/v1/invoices', $this->invoicePayload(['customer' => ['identifiers' => [['value' => 'VAT-UNKNOWN-0001']]]]), [
                'Idempotency-Key' => 'test-idem-key-unreg-0001',
            ]);

        $response->assertStatus(201)->assertJsonPath('processing_status', 'CERTIFIED');
        $this->assertDatabaseHas('reconciliation_exceptions', ['exception_type' => 'UNREGISTERED_BUYER']);
    }

    public function test_a_line_rate_that_does_not_match_the_approved_vat_rule_is_rejected(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');
        $owner = User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail();

        $payload = $this->invoicePayload();
        $payload['lines'] = [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '10.00', 'taxable_amount' => '1000.00', 'tax_amount' => '100.00']]];
        $payload['totals'] = ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '100.00', 'tax_inclusive_amount' => '1100.00', 'payable_amount' => '1100.00'];

        $response = $this->actingAs($owner)->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'test-idem-key-badrate-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'VAT_RATE_RULE_MISMATCH');
        $this->assertDatabaseMissing('invoices', ['invoice_number' => 'INV-TEST-0001']);
    }

    public function test_a_tax_category_with_no_approved_vat_rule_bound_is_rejected(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');
        $owner = User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail();

        $payload = $this->invoicePayload();
        $payload['lines'] = [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'OTHER', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']]];

        $response = $this->actingAs($owner)->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'test-idem-key-norule-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'NO_APPROVED_VAT_RULE');
    }

    public function test_a_supplier_vat_number_without_seller_capability_is_rejected(): void
    {
        $this->makeTradingParty('VAT-SUP-0001', ['BUYER']); // no SELLER capability
        $owner = User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail();

        $response = $this->actingAs($owner)->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-noseller-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'SUPPLIER_NOT_AUTHORISED');
    }

    public function test_a_duplicate_source_document_is_rejected_as_a_conflict(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-dup-first-0001'])->assertStatus(201);

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['invoice_number' => 'INV-TEST-0002']), ['Idempotency-Key' => 'test-idem-key-dup-second-0001']);

        $response->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_replaying_the_same_idempotency_key_and_payload_returns_the_identical_response(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');
        $payload = $this->invoicePayload();

        $first = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'test-idem-key-replay-0001']);
        $second = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'test-idem-key-replay-0001']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('invoice_id'), $second->json('invoice_id'));
        $this->assertSame($first->json('certificate_id'), $second->json('certificate_id'));
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_reusing_the_same_idempotency_key_with_a_different_payload_is_a_conflict(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-reuse-0001'])->assertStatus(201);

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['invoice_number' => 'INV-TEST-DIFFERENT']), ['Idempotency-Key' => 'test-idem-key-reuse-0001']);

        $response->assertStatus(409)->assertJsonPath('code', 'CONFLICT')->assertJsonFragment(['message' => 'The idempotency key was already used for a different invoice payload.']);
    }

    public function test_a_credit_note_corrects_the_original_invoice_within_its_cumulative_cap(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        $original = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-cn-orig-0001'])->assertStatus(201);
        $originalId = $original->json('invoice_id');

        $creditNote = $this->invoicePayload([
            'invoice_number' => 'CN-TEST-0001', 'document_type' => 'CREDIT_NOTE',
            'source' => ['document_id' => 'doc-test-cn-0001'],
            'original_document_reference' => ['vat_msa_invoice_id' => $originalId, 'source_document_id' => 'doc-test-0001', 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.'],
        ]);
        $creditNote['lines'] = [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '-200.00', 'net_amount' => '-200.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-200.00', 'tax_amount' => '-30.00']]];
        $creditNote['totals'] = ['line_net_amount' => '-200.00', 'tax_exclusive_amount' => '-200.00', 'tax_amount' => '-30.00', 'tax_inclusive_amount' => '-230.00', 'payable_amount' => '-230.00'];

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $creditNote, ['Idempotency-Key' => 'test-idem-key-cn-0001']);

        $response->assertStatus(201)->assertJsonPath('correction.originalInvoiceId', $originalId);
        $this->assertDatabaseHas('invoice_corrections', ['original_invoice_id' => $originalId, 'correction_type' => 'CREDIT_NOTE', 'status' => 'ACTIVE']);

        // A second credit note pushing the cumulative credit past the original's value is rejected.
        $overCredit = $creditNote;
        $overCredit['invoice_number'] = 'CN-TEST-0002';
        $overCredit['source']['document_id'] = 'doc-test-cn-0002';
        $overCredit['lines'][0]['unit_price'] = '-1000.00';
        $overCredit['lines'][0]['net_amount'] = '-1000.00';
        $overCredit['lines'][0]['tax']['taxable_amount'] = '-1000.00';
        $overCredit['lines'][0]['tax']['tax_amount'] = '-150.00';
        $overCredit['totals'] = ['line_net_amount' => '-1000.00', 'tax_exclusive_amount' => '-1000.00', 'tax_amount' => '-150.00', 'tax_inclusive_amount' => '-1150.00', 'payable_amount' => '-1150.00'];

        $overResponse = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $overCredit, ['Idempotency-Key' => 'test-idem-key-cn-over-0001']);
        $overResponse->assertStatus(409)->assertJsonFragment(['message' => 'The cumulative credit would exceed the original invoice value or VAT.']);
    }

    public function test_a_user_scoped_to_a_different_taxpayer_cannot_submit_on_behalf_of_the_supplier(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');
        $outsider = $this->makeTradingParty('VAT-OUTSIDER-0001');

        $response = $this->actingAs($outsider['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-outsider-0001']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('invoices', ['invoice_number' => 'INV-TEST-0001']);
    }

    public function test_a_national_scope_admin_can_submit_on_behalf_of_any_supplier(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-admin-0001']);

        $response->assertStatus(201);
    }

    public function test_a_user_without_the_submit_permission_is_denied(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $supplier['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-viewer-0001']);

        $response->assertStatus(403);
    }

    public function test_the_invoice_can_be_read_back_with_lines_and_ledger_entries(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        $submit = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(), ['Idempotency-Key' => 'test-idem-key-read-0001']);
        $invoiceId = $submit->json('invoice_id');

        $response = $this->actingAs($supplier['owner'])->getJson("/api/v1/invoices/{$invoiceId}");

        $response->assertStatus(200)
            ->assertJsonPath('invoiceNumber', 'INV-TEST-0001')
            ->assertJsonPath('lines.0.taxCategory', 'STANDARD')
            ->assertJsonCount(2, 'ledgerEntries');
    }
}

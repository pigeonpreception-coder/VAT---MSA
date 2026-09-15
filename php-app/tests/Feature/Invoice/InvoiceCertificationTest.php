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

    /**
     * Fraud Resistance pass (2026-09-14): a taxpayer certifying an invoice to
     * themselves (supplier and customer VAT numbers both resolve to the same
     * Taxpayer row) used to be accepted -- CERTIFIED/MATCHED at LOW risk with
     * a real verification URL and matching OUTPUT_VAT/INPUT_VAT ledger
     * entries, since InvoiceCalculator::score() has no self-dealing check at
     * all. Now rejected before any row is written.
     */
    public function test_a_self_dealing_invoice_where_supplier_and_customer_are_the_same_taxpayer_is_rejected(): void
    {
        $party = $this->makeTradingParty('VAT-SELF-0001');

        $response = $this->actingAs($party['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-SELF-0001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-SELF-0001']]],
        ]), ['Idempotency-Key' => 'test-idem-key-selfdeal-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'SELF_DEALING_NOT_PERMITTED');
        $this->assertDatabaseMissing('invoices', ['invoice_number' => 'INV-TEST-0001']);
        $this->assertDatabaseMissing('ledger_entries', ['taxpayer_id' => $party['taxpayer']->id]);
    }

    public function test_an_invoice_to_an_unregistered_buyer_is_still_certified_but_flagged(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');

        $response = $this->actingAs(User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail())
            ->postJson('/api/v1/invoices', $this->invoicePayload(['customer' => ['identifiers' => [['value' => 'VAT-UNKNOWN-0001']]]]), [
                'Idempotency-Key' => 'test-idem-key-unreg-0001',
            ]);

        $response->assertStatus(201)->assertJsonPath('processing_status', 'CERTIFIED');
        // 15 points (unregistered buyer alone) is below the MEDIUM threshold
        // (20), so this stays LOW and MATCHED/CERTIFIED -- but the exception
        // row it still creates (any non-empty reasons list gets one,
        // regardless of level) is bumped from LOW to MEDIUM severity,
        // per InvoiceCalculator::score()'s own not-otherwise-tested cutoffs.
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-TEST-0001', 'risk_level' => 'LOW']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['exception_type' => 'UNREGISTERED_BUYER', 'severity' => 'MEDIUM']);
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

    /**
     * Red-team punch list #9 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): this is the highest money-value endpoint in the
     * app; its validation had never actually been fuzzed with malformed
     * nested JSON before, only code-read as looking safe (a try/catch
     * around every line-amount parse, plus a bcmath overflow guard).
     * Confirms a handful of malformed shapes are cleanly rejected with
     * 422s, not a 500 or (worse) silently certified nonsense.
     */
    public function test_malformed_nested_payload_shapes_are_rejected_cleanly_not_with_a_server_error(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');

        // customer/supplier sent as a plain string instead of an object.
        $scalarCustomer = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['customer' => 'not an object']), ['Idempotency-Key' => 'test-idem-key-fuzz-0001']);
        $this->assertContains($scalarCustomer->status(), [422, 400], "A scalar 'customer' must be rejected cleanly, not crash: got {$scalarCustomer->status()}.");

        // tax sent as a plain string instead of an object.
        $scalarTax = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['lines' => [['line_number' => 1, 'description' => 'x', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => 'not an object']]]), ['Idempotency-Key' => 'test-idem-key-fuzz-0002']);
        $this->assertContains($scalarTax->status(), [422, 400], "A scalar 'tax' must be rejected cleanly, not crash: got {$scalarTax->status()}.");

        // A monetary amount as a wildly oversized digit string (overflow attempt).
        $overflowAmount = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => str_repeat('9', 40).'.00']]), ['Idempotency-Key' => 'test-idem-key-fuzz-0003']);
        $this->assertContains($overflowAmount->status(), [422, 400], "An overflowing 'payable_amount' must be rejected cleanly, not crash: got {$overflowAmount->status()}.");

        // lines sent as an object/map instead of a list.
        $nonListLines = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload(['lines' => ['not' => 'a list']]), ['Idempotency-Key' => 'test-idem-key-fuzz-0004']);
        $this->assertContains($nonListLines->status(), [422, 400], "Non-list 'lines' must be rejected cleanly, not crash: got {$nonListLines->status()}.");

        // None of the malformed attempts above should have created a row.
        $this->assertDatabaseCount('invoices', 0);
    }

    /** A single line at the given tax-inclusive total, ZERO_RATED so net === total and rounding never enters the picture. */
    private function zeroRatedLinePayload(int $totalCents, array $overrides = []): array
    {
        $amount = $this->centsToAmount($totalCents);

        return $this->invoicePayload(array_replace_recursive([
            'lines' => [
                ['line_number' => 1, 'description' => 'Bulk zero-rated supply', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => $amount, 'net_amount' => $amount, 'tax' => ['category' => 'ZERO_RATED', 'rate' => '0.00', 'taxable_amount' => $amount, 'tax_amount' => '0.00']],
            ],
            'totals' => ['line_net_amount' => $amount, 'tax_exclusive_amount' => $amount, 'tax_amount' => '0.00', 'tax_inclusive_amount' => $amount, 'payable_amount' => $amount],
        ], $overrides));
    }

    private function centsToAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Red-team punch list #10 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): `InvoiceCalculator::score()` had zero test coverage
     * of any kind before this -- not a single test in this file (or
     * anywhere else) asserted on `risk_level` or a value-threshold
     * boundary. This pins down the current thresholds explicitly, so any
     * future change to them is a deliberate, reviewed diff against a
     * named test rather than silent drift -- see this test class's own
     * doc comment below for what an actual *recalibration* (as opposed
     * to this correctness/coverage pass) would still need.
     */
    public function test_risk_scoring_value_thresholds_are_calibrated_as_documented(): void
    {
        $this->makeTradingParty('VAT-SUP-0001');
        $this->makeTradingParty('VAT-CUS-0001');
        $owner = fn () => User::where('email', 'vat-sup-0001-owner@test.test')->firstOrFail();

        // Just under the N$250,000 tier -- no value-based points at all.
        // This is the exact cliff a structured (split-into-smaller-invoices)
        // transaction would sit just below; see the doc comment on
        // docs/MIGRATION_MATRIX.md's own item #10 entry for why this is
        // named as a real, open limitation rather than silently accepted.
        $justBelowMedium = $this->actingAs($owner())->postJson('/api/v1/invoices', $this->zeroRatedLinePayload(24_999_999, ['invoice_number' => 'INV-RISK-0001', 'source' => ['document_id' => 'doc-risk-0001']]), ['Idempotency-Key' => 'test-idem-risk-0001']);
        $justBelowMedium->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-RISK-0001', 'risk_level' => 'LOW']);
        $this->assertDatabaseMissing('reconciliation_exceptions', ['invoice_id' => $justBelowMedium->json('invoice_id')]);

        // Exactly N$250,000 -- the MEDIUM tier's own >= boundary (35 points).
        $atMedium = $this->actingAs($owner())->postJson('/api/v1/invoices', $this->zeroRatedLinePayload(25_000_000, ['invoice_number' => 'INV-RISK-0002', 'source' => ['document_id' => 'doc-risk-0002']]), ['Idempotency-Key' => 'test-idem-risk-0002']);
        $atMedium->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-RISK-0002', 'risk_level' => 'MEDIUM']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['exception_type' => 'RISK_REVIEW', 'severity' => 'MEDIUM']);

        // Just under N$1,000,000 -- still only the MEDIUM tier's 35 points,
        // not HIGH/CRITICAL; the two value tiers don't scale smoothly, they
        // step at exactly these two named amounts.
        $justBelowCritical = $this->actingAs($owner())->postJson('/api/v1/invoices', $this->zeroRatedLinePayload(99_999_999, ['invoice_number' => 'INV-RISK-0003', 'source' => ['document_id' => 'doc-risk-0003']]), ['Idempotency-Key' => 'test-idem-risk-0003']);
        $justBelowCritical->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-RISK-0003', 'risk_level' => 'MEDIUM']);

        // Exactly N$1,000,000 -- CRITICAL (80 points) on its own, no other
        // risk factor needed; held for review (EXCEPTION), not auto-MATCHED.
        $atCritical = $this->actingAs($owner())->postJson('/api/v1/invoices', $this->zeroRatedLinePayload(100_000_000, ['invoice_number' => 'INV-RISK-0004', 'source' => ['document_id' => 'doc-risk-0004']]), ['Idempotency-Key' => 'test-idem-risk-0004']);
        $atCritical->assertStatus(201)->assertJsonPath('processing_status', 'EXCEPTION');
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-RISK-0004', 'risk_level' => 'CRITICAL']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['exception_type' => 'RISK_REVIEW', 'severity' => 'CRITICAL']);

        // Two smaller risk factors combine to cross the HIGH threshold (45)
        // even though neither alone would: the N$250,000 MEDIUM tier (35)
        // plus mixed VAT categories (10) = 45 = HIGH, held for review.
        $mixedCategories = $this->zeroRatedLinePayload(25_000_000, [
            'invoice_number' => 'INV-RISK-0005', 'source' => ['document_id' => 'doc-risk-0005'],
            'lines' => [
                ['line_number' => 1, 'description' => 'Zero-rated half', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '125000.00', 'net_amount' => '125000.00', 'tax' => ['category' => 'ZERO_RATED', 'rate' => '0.00', 'taxable_amount' => '125000.00', 'tax_amount' => '0.00']],
                ['line_number' => 2, 'description' => 'Exempt half', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '125000.00', 'net_amount' => '125000.00', 'tax' => ['category' => 'EXEMPT', 'rate' => '0.00', 'taxable_amount' => '125000.00', 'tax_amount' => '0.00']],
            ],
        ]);
        $combined = $this->actingAs($owner())->postJson('/api/v1/invoices', $mixedCategories, ['Idempotency-Key' => 'test-idem-risk-0005']);
        $combined->assertStatus(201)->assertJsonPath('processing_status', 'EXCEPTION');
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-RISK-0005', 'risk_level' => 'HIGH']);
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
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
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

<?php

namespace Tests\Feature\Invoice;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers InvoiceService::cancel/explainVat/transactionTimeline (ported from
 * lib/data/repository.ts's cancelInvoice/explainInvoiceVat/
 * getTransactionTimeline, Module 2 Phases A-D) over real HTTP against
 * MySQL -- the rest of Phase 9's own deferred scope, closed out alongside
 * the VAT-return-generation prerequisite and the refund workflow it
 * unblocked.
 */
class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

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

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot-admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0',
            'invoice_number' => 'INV-'.Str::random(8),
            'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
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

    /** @return array{supplier: array, customer: array, invoiceId: string} */
    private function certifyInvoice(): array
    {
        $supplier = $this->makeTradingParty('VAT-SUP-2001');
        $customer = $this->makeTradingParty('VAT-CUS-2001');
        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => 'VAT-SUP-2001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-CUS-2001']]],
        ]), ['Idempotency-Key' => 'test-idem-'.Str::random(20)]);
        $response->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');

        return ['supplier' => $supplier, 'customer' => $customer, 'invoiceId' => $response->json('invoice_id')];
    }

    public function test_vat_explanation_and_transaction_timeline_reflect_a_freshly_certified_invoice(): void
    {
        $ctx = $this->certifyInvoice();

        $explanation = $this->actingAs($ctx['supplier']['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/vat-explanation");
        $explanation->assertStatus(200)
            ->assertJsonPath('invoiceId', $ctx['invoiceId'])
            ->assertJsonPath('lines.0.taxCategory', 'STANDARD')
            ->assertJsonPath('lines.0.vatRuleId', 'vrule-standard-na')
            ->assertJsonPath('lines.0.vatRuleVersion', 1)
            ->assertJsonPath('lines.0.taxAmountCents', 15000);

        $timeline = $this->actingAs($ctx['customer']['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/transaction-timeline");
        $timeline->assertStatus(200)
            ->assertJsonPath('rootInvoiceId', $ctx['invoiceId'])
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.transactionType', 'CERTIFICATION')
            ->assertJsonCount(2, 'events.0.ledgerEntries');
    }

    public function test_vat_explanation_and_transaction_timeline_are_scoped_to_the_supplier_or_customer_only(): void
    {
        $ctx = $this->certifyInvoice();
        $stranger = $this->makeTradingParty('VAT-SUP-2002');

        $this->actingAs($stranger['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/vat-explanation")->assertStatus(404);
        $this->actingAs($stranger['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/transaction-timeline")->assertStatus(404);
    }

    public function test_a_pilot_admin_can_cancel_an_invoice_which_is_idempotent_and_reverses_the_ledger(): void
    {
        $ctx = $this->certifyInvoice();
        $admin = $this->pilotAdmin();

        $cancel = $this->actingAs($admin)
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Duplicate submission from the source ERP system.']);
        $cancel->assertStatus(200)->assertJsonPath('cancellation.status', 'CANCELLED');

        $this->assertDatabaseHas('invoices', ['id' => $ctx['invoiceId'], 'status' => 'CANCELLED']);
        $this->assertDatabaseCount('vat_transactions', 2);
        $this->assertDatabaseHas('vat_transactions', ['invoice_id' => $ctx['invoiceId'], 'transaction_type' => 'CANCELLATION']);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertDatabaseHas('ledger_entries', ['invoice_id' => $ctx['invoiceId'], 'entry_type' => 'OUTPUT_VAT', 'direction' => 'DEBIT', 'amount_cents' => 15000]);
        $this->assertDatabaseHas('ledger_entries', ['invoice_id' => $ctx['invoiceId'], 'entry_type' => 'INPUT_VAT', 'direction' => 'CREDIT', 'amount_cents' => 15000]);
        $this->assertDatabaseHas('audit_events', ['action' => 'INVOICE_CANCELLED', 'resource_id' => $ctx['invoiceId']]);

        // Idempotent: cancelling an already-cancelled invoice is a clean no-op, not a second reversal.
        $again = $this->actingAs($admin)
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Retried after a network timeout.']);
        $again->assertStatus(200)->assertJsonPath('cancellation.status', 'CANCELLED');
        $this->assertDatabaseCount('vat_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);

        $timeline = $this->actingAs($ctx['supplier']['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/transaction-timeline");
        $timeline->assertStatus(200)->assertJsonCount(2, 'events');
    }

    /**
     * Resilience to User Errors pass (2026-09-14): the test above proves
     * cancel() is idempotent for a *sequential* double-cancel (the second
     * call reads status=CANCELLED and short-circuits before writing
     * anything). It does not prove the genuinely concurrent case: two
     * overlapping cancel() calls on the same still-active invoice could
     * both pass that pre-check before either commits, and (pre-fix) both
     * would then create their own reversing VatTransaction/LedgerEntry
     * pair -- double-counting the VAT reversal. Simulated the same way as
     * this pass's other race regression tests: a `DB::listen()` hook fires
     * a real, separate cancel the instant after this request's own read
     * query returns.
     */
    public function test_a_cancellation_that_races_a_concurrent_cancellation_does_not_double_reverse_the_ledger(): void
    {
        $ctx = $this->certifyInvoice();
        $admin = $this->pilotAdmin();

        $sabotaged = false;
        DB::listen(function ($query) use (&$sabotaged, $ctx) {
            if ($sabotaged || ! str_contains($query->sql, 'select * from `invoices`')) {
                return;
            }
            $sabotaged = true;
            DB::table('invoices')->where('id', $ctx['invoiceId'])->update(['status' => 'CANCELLED']);
            DB::table('vat_transactions')->insert([
                'id' => (string) Str::uuid(), 'invoice_id' => $ctx['invoiceId'], 'taxpayer_id' => $ctx['supplier']['taxpayer']->id,
                'transaction_type' => 'CANCELLATION', 'reference_transaction_id' => null, 'created_at' => now(),
            ]);
        });

        $response = $this->actingAs($admin)
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Concurrent cancellation attempt.']);

        $response->assertStatus(409);
        // Only the concurrent winners own CANCELLATION transaction should exist -- not a second, doubled reversal.
        $this->assertDatabaseCount('vat_transactions', 2);
        // The concurrent winner reversed once; this request must not have added a second reversal.
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    /**
     * Broader security-sweep follow-up (2026-09-20), on the InvoiceService
     * this session's own New Credit Note form (InvoiceCorrectionViewController)
     * reuses unchanged: resolveOriginalInvoice()'s cumulative-credit-cap
     * check used to run *before* submit()'s own DB::transaction even
     * opened -- a plain read with no lock. Two concurrent credit notes
     * against the same original invoice, each individually within the cap,
     * could both read the same pre-commit "prior credited" total, both
     * pass, and together exceed the original invoice's value -- and unlike
     * the idempotency-key/invoice-number races elsewhere in this method,
     * there is no UNIQUE constraint backstopping an aggregate SUM, so nothing
     * would have caught it. Fixed by moving the check into the transaction
     * under `lockForUpdate()` on the original invoice
     * (InvoiceService::enforceCumulativeCreditCap()). Reproduced the same
     * way as this file's own cancellation-race test above: a `DB::listen()`
     * hook inserts a real, fully-formed "concurrent" ACTIVE credit note for
     * 690.00 the instant this request's own lock-acquiring SELECT fires --
     * so by the time the fresh post-lock SUM query runs, a second 690.00
     * credit note (combined: 1,380.00) already exists against a 1,150.00
     * original, which the fix must now catch (pre-fix, the earlier,
     * pre-transaction read would have missed it and let this request's own
     * 690.00 land too).
     */
    public function test_a_credit_note_that_races_a_concurrent_credit_note_does_not_jointly_exceed_the_original_invoice_value(): void
    {
        $ctx = $this->certifyInvoice();

        $raced = false;
        DB::listen(function ($query) use (&$raced, $ctx) {
            if ($raced || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $raced = true;
            $concurrentInvoiceId = (string) Str::uuid();
            DB::table('invoices')->insert([
                'id' => $concurrentInvoiceId, 'invoice_number' => 'CN-RACE-'.Str::random(6), 'document_type' => 'CREDIT_NOTE',
                'source_system' => 'erp-test', 'source_document_id' => 'doc-race-'.Str::random(8),
                'supplier_taxpayer_id' => $ctx['supplier']['taxpayer']->id, 'supplier_name' => $ctx['supplier']['taxpayer']->legal_name,
                'supplier_vat_number' => $ctx['supplier']['taxpayer']->vat_number, 'customer_taxpayer_id' => $ctx['customer']['taxpayer']->id,
                'customer_name' => $ctx['customer']['taxpayer']->legal_name, 'customer_vat_number' => $ctx['customer']['taxpayer']->vat_number,
                'issue_date' => '2026-09-05', 'currency' => 'NAD', 'line_net_cents' => -60000, 'tax_cents' => -9000, 'total_cents' => -69000,
                'status' => 'MATCHED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', 'race-'.$concurrentInvoiceId),
                'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                'verification_token' => 'vfy_'.str_replace('-', '', (string) Str::uuid()), 'created_at' => now(), 'certified_at' => now(),
            ]);
            DB::table('invoice_corrections')->insert([
                'id' => (string) Str::uuid(), 'original_invoice_id' => $ctx['invoiceId'], 'correction_invoice_id' => $concurrentInvoiceId,
                'correction_type' => 'CREDIT_NOTE', 'reason_code' => 'PRICING_ERROR', 'reason' => 'Concurrent credit note landing mid-request.',
                'status' => 'ACTIVE', 'created_by' => $ctx['supplier']['owner']->id, 'created_at' => now(),
            ]);
        });

        $originalInvoice = Invoice::findOrFail($ctx['invoiceId']);
        $response = $this->actingAs($ctx['supplier']['owner'])->postJson('/api/v1/invoices', [
            'schema_version' => '1.0.0', 'invoice_number' => 'CN-'.Str::random(8), 'document_type' => 'CREDIT_NOTE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-05T09:00:00Z'],
            'supplier' => ['name' => $ctx['supplier']['taxpayer']->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $ctx['supplier']['taxpayer']->vat_number]]],
            'customer' => ['name' => $ctx['customer']['taxpayer']->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $ctx['customer']['taxpayer']->vat_number]]],
            'issue_date' => '2026-09-05', 'currency' => 'NAD',
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '0.6', 'unit_code' => 'EA', 'unit_price' => '-1000.00', 'net_amount' => '-600.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-600.00', 'tax_amount' => '-90.00']]],
            'totals' => ['line_net_amount' => '-600.00', 'tax_exclusive_amount' => '-600.00', 'tax_amount' => '-90.00', 'tax_inclusive_amount' => '-690.00', 'payable_amount' => '-690.00'],
            'original_document_reference' => ['vat_msa_invoice_id' => $ctx['invoiceId'], 'source_document_id' => $originalInvoice->source_document_id, 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.'],
        ], ['Idempotency-Key' => 'test-idem-race-'.Str::random(20)]);

        // The sabotage insert lands inside this request's own DB::transaction (there is no
        // `lockForUpdate` query -- and so no hook trigger, no sabotage, no rejection -- at all
        // on the pre-fix code path, which is itself the regression this test is pinned to), so
        // the RepositoryConflictException the fix throws rolls the sabotage row back out along
        // with this request's own attempted one. What proves the fix is the 409 itself: the
        // fresh, post-lock sum query inside enforceCumulativeCreditCap() saw the sabotage
        // row's -690.00 and combined it with this request's own -690.00 to correctly compute
        // -1,380.00 against a 1,150.00 original, rather than the pre-fix code's stale,
        // pre-transaction read of "nothing credited yet" that would have missed it entirely.
        $this->assertTrue($raced, 'The DB::listen() hook must have fired to simulate the race (it never fires at all on the pre-fix code path, which has no lockForUpdate query).');
        $response->assertStatus(409);
        $this->assertDatabaseCount('invoice_corrections', 0);
        $this->assertSame(0, Invoice::where('document_type', 'CREDIT_NOTE')->count());
    }

    public function test_cancellation_requires_permission_a_valid_reason_and_step_up_confirmation(): void
    {
        $ctx = $this->certifyInvoice();
        $admin = $this->pilotAdmin();

        // No invoices:cancel permission on the supplier's own owner role.
        $this->actingAs($ctx['supplier']['owner'])
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Attempting self-cancellation.'])
            ->assertStatus(403);

        // A reason under 10 characters is rejected.
        $this->actingAs($admin)
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Too short'])
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $ctx['invoiceId'], 'status' => 'MATCHED']);
    }

    public function test_transaction_timeline_resolves_from_any_invoice_in_a_correction_lineage_and_the_active_correction_blocks_cancellation(): void
    {
        $ctx = $this->certifyInvoice();
        $originalInvoice = Invoice::findOrFail($ctx['invoiceId']);
        $originalSourceDocId = $originalInvoice->source_document_id;

        $creditNote = $this->actingAs($ctx['supplier']['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'invoice_number' => 'CN-'.Str::random(8), 'document_type' => 'CREDIT_NOTE',
            'source' => ['document_id' => 'doc-cn-'.Str::random(8)],
            'supplier' => ['identifiers' => [['value' => 'VAT-SUP-2001']]],
            'customer' => ['identifiers' => [['value' => 'VAT-CUS-2001']]],
            'original_document_reference' => ['vat_msa_invoice_id' => $ctx['invoiceId'], 'source_document_id' => $originalSourceDocId, 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.'],
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '-100.00', 'net_amount' => '-100.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-100.00', 'tax_amount' => '-15.00']]],
            'totals' => ['line_net_amount' => '-100.00', 'tax_exclusive_amount' => '-100.00', 'tax_amount' => '-15.00', 'tax_inclusive_amount' => '-115.00', 'payable_amount' => '-115.00'],
        ]), ['Idempotency-Key' => 'test-idem-cn-'.Str::random(20)]);
        $creditNote->assertStatus(201);
        $creditNoteId = $creditNote->json('invoice_id');

        $timeline = $this->actingAs($ctx['supplier']['owner'])->getJson("/api/v1/invoices/{$creditNoteId}/transaction-timeline");
        $timeline->assertStatus(200)
            ->assertJsonPath('rootInvoiceId', $ctx['invoiceId'])
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.transactionType', 'CERTIFICATION')
            ->assertJsonPath('events.1.transactionType', 'CORRECTION');

        // The original now carries an active correction, so cancelling it is refused.
        $this->actingAs($this->pilotAdmin())
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Attempting to cancel a corrected invoice.'])
            ->assertStatus(409);

        // The credit note itself is not an original tax invoice and cannot be cancelled either.
        $this->actingAs($this->pilotAdmin())
            ->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$creditNoteId}/cancellation", ['reason' => 'Attempting to cancel a credit note directly.'])
            ->assertStatus(422);
    }
}

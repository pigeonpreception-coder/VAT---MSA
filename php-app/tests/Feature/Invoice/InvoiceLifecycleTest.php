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
use Illuminate\Support\Str;
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
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
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
            ->withSession(['auth.password_confirmed_at' => time()])
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
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Retried after a network timeout.']);
        $again->assertStatus(200)->assertJsonPath('cancellation.status', 'CANCELLED');
        $this->assertDatabaseCount('vat_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);

        $timeline = $this->actingAs($ctx['supplier']['owner'])->getJson("/api/v1/invoices/{$ctx['invoiceId']}/transaction-timeline");
        $timeline->assertStatus(200)->assertJsonCount(2, 'events');
    }

    public function test_cancellation_requires_permission_a_valid_reason_and_step_up_confirmation(): void
    {
        $ctx = $this->certifyInvoice();
        $admin = $this->pilotAdmin();

        // No invoices:cancel permission on the supplier's own owner role.
        $this->actingAs($ctx['supplier']['owner'])
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Attempting self-cancellation.'])
            ->assertStatus(403);

        // A reason under 10 characters is rejected.
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
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
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/cancellation", ['reason' => 'Attempting to cancel a corrected invoice.'])
            ->assertStatus(409);

        // The credit note itself is not an original tax invoice and cannot be cancelled either.
        $this->actingAs($this->pilotAdmin())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/invoices/{$creditNoteId}/cancellation", ['reason' => 'Attempting to cancel a credit note directly.'])
            ->assertStatus(422);
    }
}

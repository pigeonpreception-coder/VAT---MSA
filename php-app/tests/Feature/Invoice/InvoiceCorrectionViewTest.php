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
 * Covers the real Blade UI for the New Credit Note / New Debit Note pages
 * (App\Http\Controllers\Invoice\InvoiceCorrectionViewController /
 * resources/views/invoices/new-{credit,debit}-note.blade.php) -- the two
 * routes this replaces were the only $plannedRoute placeholders this
 * session that carried an explicit change-control note ("an unapproved
 * form must be proposed before it is built") rather than a stale scope
 * note; the form design below was proposed and approved first (see
 * docs/MIGRATION_MATRIX.md). The write path itself
 * (App\Services\Invoice\InvoiceService::submit with document_type
 * CREDIT_NOTE/DEBIT_NOTE) is already covered end to end by
 * tests/Feature/Invoice/InvoiceLifecycleTest.php, so this file's own job
 * is the access gates, the original-invoice picker, the credit note's
 * line-crediting form, and the debit note's free-form line.
 */
class InvoiceCorrectionViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@invcorrview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /** @return array{supplier: array, customer: array, invoiceId: string} */
    private function certifyInvoice(string $supplierVat, string $customerVat, array $overrides = []): array
    {
        $supplier = $this->makeTradingParty($supplierVat);
        $customer = $this->makeTradingParty($customerVat);
        $payload = array_replace_recursive([
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-'.Str::random(8), 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => "{$supplierVat} Trading Co", 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $supplierVat]]],
            'customer' => ['name' => "{$customerVat} Trading Co", 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $customerVat]]],
            'issue_date' => '2026-09-01', 'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '2', 'unit_code' => 'EA', 'unit_price' => '500.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']],
            ],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'test-idem-cn-view-'.Str::random(20)]);
        $response->assertStatus(201);

        return ['supplier' => $supplier, 'customer' => $customer, 'invoiceId' => $response->json('invoice_id')];
    }

    // -- access gates --

    public function test_the_new_credit_note_page_requires_authentication(): void
    {
        $this->get('/new-registration/credit-note')->assertRedirect('/login');
    }

    public function test_a_role_without_invoices_read_is_denied_on_both_pages(): void
    {
        $noAccess = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Access', 'email' => 'noaccess@invcorrview.test',
            'password' => bcrypt('password'), 'role' => 'INTERNAL_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($noAccess)->get('/new-registration/credit-note')->assertForbidden();
        $this->actingAs($noAccess)->get('/new-registration/debit-note')->assertForbidden();
    }

    // -- picker / eligibility --

    public function test_the_credit_note_page_lists_only_the_actors_own_certified_originals(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0001', 'VAT-CNVIEW-0002');
        $otherSupplier = $this->makeTradingParty('VAT-CNVIEW-0003');

        $response = $this->actingAs($ctx['supplier']['owner'])->get('/new-registration/credit-note');

        $response->assertOk()->assertViewIs('invoices.new-credit-note');
        $response->assertSee($ctx['invoiceId']);
        $this->actingAs($otherSupplier['owner'])->get('/new-registration/credit-note')->assertDontSee($ctx['invoiceId']);
    }

    public function test_selecting_an_original_invoice_shows_its_lines_and_remaining_creditable_amount(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0101', 'VAT-CNVIEW-0102');

        $response = $this->actingAs($ctx['supplier']['owner'])->get('/new-registration/credit-note?invoice_id='.$ctx['invoiceId']);

        $response->assertOk();
        $response->assertSee('Consulting services');
        $response->assertSee('NAD 1,150.00'); // original total and, since nothing has been credited yet, the remaining creditable amount
    }

    // -- credit note --

    public function test_a_credit_note_can_be_issued_against_one_line_of_the_original(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0011', 'VAT-CNVIEW-0012');
        $lineId = \App\Models\InvoiceLine::where('invoice_id', $ctx['invoiceId'])->where('line_number', 1)->value('id');

        $response = $this->actingAs($ctx['supplier']['owner'])->post('/new-registration/credit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'pricing_error', 'reason' => 'Agreed pricing correction.',
            'issue_date' => '2026-09-05', 'credit_quantity' => [$lineId => '1'],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/invoices/', $response->headers->get('Location'));
        $this->assertDatabaseHas('invoices', ['document_type' => 'CREDIT_NOTE', 'total_cents' => -57500]);
        $this->assertDatabaseHas('invoice_corrections', ['original_invoice_id' => $ctx['invoiceId'], 'correction_type' => 'CREDIT_NOTE', 'reason_code' => 'PRICING_ERROR', 'status' => 'ACTIVE']);
    }

    public function test_a_credit_note_cannot_credit_more_than_the_original_line_quantity(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0021', 'VAT-CNVIEW-0022');
        $lineId = \App\Models\InvoiceLine::where('invoice_id', $ctx['invoiceId'])->where('line_number', 1)->value('id');

        $response = $this->actingAs($ctx['supplier']['owner'])->post('/new-registration/credit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.',
            'issue_date' => '2026-09-05', 'credit_quantity' => [$lineId => '3'],
        ]);

        $response->assertRedirect('/new-registration/credit-note?invoice_id='.$ctx['invoiceId']);
        $response->assertSessionHasErrors('credit_quantity');
        $this->assertDatabaseMissing('invoices', ['document_type' => 'CREDIT_NOTE']);
    }

    public function test_a_credit_note_with_no_lines_credited_is_rejected(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0031', 'VAT-CNVIEW-0032');

        $response = $this->actingAs($ctx['supplier']['owner'])->post('/new-registration/credit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.', 'issue_date' => '2026-09-05',
        ]);

        $response->assertSessionHasErrors('credit_quantity');
        $this->assertDatabaseMissing('invoices', ['document_type' => 'CREDIT_NOTE']);
    }

    public function test_a_role_without_invoices_submit_cannot_issue_a_credit_note(): void
    {
        $ctx = $this->certifyInvoice('VAT-CNVIEW-0041', 'VAT-CNVIEW-0042');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Read Only', 'email' => 'readonly@invcorrview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $ctx['supplier']['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $lineId = \App\Models\InvoiceLine::where('invoice_id', $ctx['invoiceId'])->where('line_number', 1)->value('id');

        $response = $this->actingAs($viewer)->post('/new-registration/credit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.',
            'issue_date' => '2026-09-05', 'credit_quantity' => [$lineId => '1'],
        ]);

        $response->assertForbidden();
    }

    // -- debit note --

    public function test_a_debit_note_can_be_issued_with_a_free_form_line(): void
    {
        $ctx = $this->certifyInvoice('VAT-DNVIEW-0011', 'VAT-DNVIEW-0012');

        $response = $this->actingAs($ctx['supplier']['owner'])->post('/new-registration/debit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'additional_charge', 'reason' => 'Additional delivery charge agreed after the fact.',
            'issue_date' => '2026-09-05', 'description' => 'Delivery surcharge', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price_cents' => 20000,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/invoices/', $response->headers->get('Location'));
        $this->assertDatabaseHas('invoices', ['document_type' => 'DEBIT_NOTE', 'total_cents' => 23000]);
        $this->assertDatabaseHas('invoice_corrections', ['original_invoice_id' => $ctx['invoiceId'], 'correction_type' => 'DEBIT_NOTE', 'reason_code' => 'ADDITIONAL_CHARGE', 'status' => 'ACTIVE']);
    }

    public function test_a_debit_note_requires_a_positive_quantity_and_unit_price(): void
    {
        $ctx = $this->certifyInvoice('VAT-DNVIEW-0021', 'VAT-DNVIEW-0022');

        $response = $this->actingAs($ctx['supplier']['owner'])->post('/new-registration/debit-note', [
            'invoice_id' => $ctx['invoiceId'], 'reason_code' => 'ADDITIONAL_CHARGE', 'reason' => 'Additional delivery charge agreed after the fact.',
            'issue_date' => '2026-09-05', 'description' => 'Delivery surcharge', 'quantity' => '0', 'unit_code' => 'EA', 'unit_price_cents' => 20000,
        ]);

        $response->assertSessionHasErrors('quantity');
        $this->assertDatabaseMissing('invoices', ['document_type' => 'DEBIT_NOTE']);
    }
}

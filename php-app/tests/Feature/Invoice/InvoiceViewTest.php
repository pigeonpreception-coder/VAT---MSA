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
 * Covers the real Blade UI for the invoices module
 * (App\Http\Controllers\Invoice\InvoiceViewController /
 * resources/views/invoices/{index,show}.blade.php) -- the first
 * business-module screens of the new frontend build-out that started
 * with the dashboard. Reuses InvoiceLifecycleTest's own "certify via the
 * real command, not a raw DB row" convention, since a document_record
 * view genuinely depends on the certificate/ledger side effects only a
 * real certification produces.
 */
class InvoiceViewTest extends TestCase
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

    /** Holds neither invoices:read nor invoices:submit -- the fully-denied fixture. */
    private function developerPartner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'developer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
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
    private function certifyInvoice(string $supplierVat = 'VAT-VIEW-SUP-0001', string $customerVat = 'VAT-VIEW-CUS-0001'): array
    {
        $supplier = $this->makeTradingParty($supplierVat);
        $customer = $this->makeTradingParty($customerVat);
        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'test-idem-'.Str::random(20)]);
        $response->assertStatus(201);

        return ['supplier' => $supplier, 'customer' => $customer, 'invoiceId' => $response->json('invoice_id')];
    }

    public function test_the_invoices_list_requires_authentication(): void
    {
        $this->get('/invoices')->assertRedirect('/login');
    }

    public function test_the_invoices_list_requires_the_invoices_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/invoices')->assertForbidden();
    }

    public function test_the_invoices_list_renders_a_certified_invoice_with_a_working_link_to_its_detail_page(): void
    {
        $ctx = $this->certifyInvoice();

        $response = $this->actingAs($ctx['supplier']['owner'])->get('/invoices');

        $response->assertOk()->assertViewIs('invoices.index');
        $response->assertSee('Certified tax invoices');
        $response->assertSee('Supplier Co');
        $response->assertSee('NAD 150.00'); // VAT column
        $response->assertSee(route('invoices.show', $ctx['invoiceId']), false);
        // Accessible-table structure: a real <caption> and scope="col" headers, not bare <th>.
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_the_invoices_list_only_shows_the_actors_own_taxpayers_invoices(): void
    {
        $ctx = $this->certifyInvoice('VAT-VIEW-SUP-0002', 'VAT-VIEW-CUS-0002');
        $outsider = $this->makeTradingParty('VAT-VIEW-OUT-0002');

        $response = $this->actingAs($outsider['owner'])->get('/invoices');

        $response->assertOk();
        $response->assertDontSee($ctx['invoiceId']);
    }

    public function test_the_invoice_detail_page_requires_authentication(): void
    {
        // The redirect happens before the controller ever resolves an
        // invoice, so no real id is needed -- and calling certifyInvoice()
        // first would leave the test session authenticated as its actor
        // (actingAs() persists for the rest of the test method), defeating
        // the very thing being checked here.
        $this->get('/invoices/'.((string) Str::uuid()))->assertRedirect('/login');
    }

    public function test_the_invoice_detail_page_requires_the_invoices_read_permission(): void
    {
        $ctx = $this->certifyInvoice('VAT-VIEW-SUP-0003', 'VAT-VIEW-CUS-0003');

        $this->actingAs($this->developerPartner())->get("/invoices/{$ctx['invoiceId']}")->assertForbidden();
    }

    public function test_the_invoice_detail_page_renders_the_full_certification_record(): void
    {
        $ctx = $this->certifyInvoice('VAT-VIEW-SUP-0004', 'VAT-VIEW-CUS-0004');

        $response = $this->actingAs($ctx['supplier']['owner'])->get("/invoices/{$ctx['invoiceId']}?created=1");

        $response->assertOk()->assertViewIs('invoices.show');
        $response->assertSee('Invoice certified successfully.');
        $response->assertSee('Document record');
        $response->assertSee('Certification receipt');
        $response->assertSee('Invoice lines');
        $response->assertSee('VAT sub-ledger postings');
        $response->assertSee('Consulting services');
        // Net and VAT are shown (in the document record panel and again per
        // invoice line); totalCents itself is never rendered on this page,
        // matching the source's own detail page exactly.
        $response->assertSeeInOrder(['Net value', 'NAD 1,000.00', 'VAT amount', 'NAD 150.00']);
        $response->assertSee('Certificate ID'); // the certificate/transaction/hash detail block rendered
    }

    public function test_the_invoice_detail_page_404s_for_an_invoice_outside_the_actors_taxpayer_scope(): void
    {
        $ctx = $this->certifyInvoice('VAT-VIEW-SUP-0005', 'VAT-VIEW-CUS-0005');
        $outsider = $this->makeTradingParty('VAT-VIEW-OUT-0005');

        $this->actingAs($outsider['owner'])->get("/invoices/{$ctx['invoiceId']}")->assertNotFound();
    }

    public function test_the_invoice_detail_page_404s_for_an_unknown_id(): void
    {
        $someone = $this->makeTradingParty('VAT-VIEW-SUP-0006');

        $this->actingAs($someone['owner'])->get('/invoices/'.((string) Str::uuid()))->assertNotFound();
    }
}

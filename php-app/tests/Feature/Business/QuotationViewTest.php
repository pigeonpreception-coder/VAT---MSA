<?php

namespace Tests\Feature\Business;

use App\Models\CounterpartyTrustProfile;
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
 * Covers the real Blade UI for the quotation register/lifecycle/edit
 * (App\Http\Controllers\Business\QuotationViewController /
 * resources/views/quotations/{index,edit}.blade.php) -- ported from the
 * source's own app/commercial/page.tsx + QuotationForm.tsx +
 * QuotationActions.tsx + app/commercial/quotations/[id]/edit/page.tsx +
 * QuotationEditForm.tsx. Reuses App\Services\Business\QuotationService
 * directly (already covered end to end by
 * tests/Feature/Business/BusinessPartyAndQuotationTest.php), so this file's
 * own job is the access gate, the create/send/accept/reject/expire/convert
 * form flows, and the multi-line edit form.
 */
class QuotationViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@quoteview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /** See tests/Feature/Portal/SellerPortalTest.php's own createCustomerParty() doc comment. */
    private function createCustomerParty(User $owner, string $vatNumber = 'VAT-CUST-0001', string $displayName = 'Acme Customer'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/business-parties', [
            'schema_version' => '1.0.0', 'display_name' => $displayName, 'vat_number' => $vatNumber, 'relationships' => ['CUSTOMER'],
        ], ['Idempotency-Key' => 'test-idem-cust-'.$vatNumber]);
        $id = $response->json('resource.id');
        CounterpartyTrustProfile::where('business_party_id', $id)->update([
            'trust_status' => 'AUTHORITY_VERIFIED', 'tax_registration_status' => 'ACTIVE',
            'checked_at' => now(), 'expires_at' => now()->addYear(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function quotationFormPayload(string $customerPartyId, array $overrides = []): array
    {
        return array_replace([
            'quotation_number' => 'QUO-VIEW-0001', 'customer_party_id' => $customerPartyId,
            'issue_date' => '2026-09-01', 'valid_until' => '2026-09-30',
            'description' => 'Consulting services', 'quantity' => 1, 'unit_code' => 'EA', 'unit_price_cents' => 100000,
        ], $overrides);
    }

    public function test_the_quotations_page_requires_authentication(): void
    {
        $this->get('/quotations')->assertRedirect('/login');
    }

    public function test_a_role_without_commercial_read_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-DENY-0001');
        $noAccess = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Access', 'email' => 'noaccess@quoteview.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($noAccess)->get('/quotations')->assertForbidden();
    }

    public function test_the_quotations_page_renders_the_register_and_issue_form(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));

        $response = $this->actingAs($seller['owner'])->get('/quotations');

        $response->assertOk()->assertViewIs('quotations.index');
        $response->assertSee('QUO-VIEW-0001');
        $response->assertSee('Acme Customer');
        $response->assertSee('Issue quotation');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_a_quotation_can_be_created_through_the_form(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0002');
        $customerPartyId = $this->createCustomerParty($seller['owner']);

        $response = $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));

        $response->assertRedirect('/quotations');
        $response->assertSessionHas('status', 'Quotation issued.');
        $this->assertDatabaseHas('quotations', ['quotation_number' => 'QUO-VIEW-0001', 'status' => 'DRAFT', 'total_cents' => 115000]);
    }

    /**
     * Input Validation & Robustness pass (2026-09-14): createPayload()
     * used to `(int) $request->input('unit_price_cents', 0)` and
     * `(int) round((float) $request->input('quantity', 0) * 1_000_000)`
     * before validation -- a non-numeric submission silently coerced to
     * 0 rather than being rejected. Now rejected cleanly via
     * Controller::safeIntegerInput()/safeMicrosInput().
     */
    public function test_a_non_numeric_unit_price_or_quantity_is_rejected_not_silently_zeroed(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0002B');
        $customerPartyId = $this->createCustomerParty($seller['owner']);

        $response = $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId, [
            'quotation_number' => 'QUO-NAN-0001', 'unit_price_cents' => 'not-a-number', 'quantity' => 'also-not-a-number',
        ]));

        $response->assertRedirect('/quotations');
        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('quotations', ['quotation_number' => 'QUO-NAN-0001']);
    }

    public function test_a_role_without_quotations_manage_cannot_issue_a_quotation(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0003');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@quoteview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->post('/quotations', $this->quotationFormPayload($customerPartyId))->assertForbidden();
    }

    public function test_a_draft_quotation_can_be_sent_then_accepted_and_converted_to_an_invoice(): void
    {
        // Closes the source's own dead end: a quotation created through this
        // screen would otherwise sit in DRAFT with no reachable action --
        // see QuotationViewController's own doc comment.
        $seller = $this->makeOrganisation('VAT-SELLER-0004');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-0001')->firstOrFail()->id;

        $send = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");
        $send->assertRedirect('/quotations');
        $send->assertSessionHas('status', 'Quotation sent to the customer.');
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'ISSUED']);

        $accept = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/accept");
        $accept->assertSessionHas('status', 'Quotation accepted.');
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'ACCEPTED']);

        $convert = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/convert", [
            'invoice_number' => 'INV-FROM-VIEW-0001', 'issue_date' => '2026-09-02',
        ]);
        $convert->assertRedirect();
        $this->assertStringContainsString('/invoices/', $convert->headers->get('Location'));
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'CONVERTED']);
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-FROM-VIEW-0001', 'total_cents' => 115000]);
    }

    public function test_an_issued_quotation_can_be_rejected_with_a_reason(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0005');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-0001')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");

        $response = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/rejection", ['reason' => 'Customer no longer needs the service.']);

        $response->assertSessionHas('status', 'Quotation rejected.');
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'REJECTED']);
    }

    public function test_an_overdue_issued_quotation_can_be_expired(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0006');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId, [
            'quotation_number' => 'QUO-OVERDUE-0001', 'issue_date' => '2020-01-01', 'valid_until' => '2020-01-31',
        ]));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-OVERDUE-0001')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");

        $response = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/expiration");

        $response->assertSessionHas('status', 'Quotation expired.');
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => 'EXPIRED']);
    }

    public function test_the_edit_form_renders_prefilled_lines_for_an_issued_quotation(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0007');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-0001')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");

        $response = $this->actingAs($seller['owner'])->get("/quotations/{$quotationId}/edit");

        $response->assertOk()->assertViewIs('quotations.edit');
        $response->assertSee('Consulting services');
        $response->assertSee('Save quotation revision');
        // 2, not 1: CREATE (revision 1) plus the SEND transition just above (revision 2).
        $response->assertViewHas('quotation', fn ($quotation) => $quotation['revision_count'] === 2);
    }

    public function test_an_issued_quotation_can_be_edited_with_two_lines(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0008');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-0001')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");

        $response = $this->actingAs($seller['owner'])->patch("/quotations/{$quotationId}", [
            'quotation_number' => 'QUO-VIEW-0001', 'customer_party_id' => $customerPartyId,
            'issue_date' => '2026-09-01', 'valid_until' => '2026-09-30',
            'lines' => [
                ['description' => 'Consulting services', 'quantity' => 1, 'unit_code' => 'EA', 'unit_price_cents' => 100000, 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500],
                ['description' => 'Training session', 'quantity' => 2, 'unit_code' => 'EA', 'unit_price_cents' => 50000, 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500],
            ],
        ]);

        $response->assertRedirect('/quotations');
        $response->assertSessionHas('status', 'Quotation revision saved.');
        $this->assertDatabaseCount('quotation_lines', 2);
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'total_cents' => 230000]);
        // Revision 3, not 2: CREATE (1), the SEND transition above (2), then this EDIT (3).
        $this->assertDatabaseHas('quotation_revisions', ['quotation_id' => $quotationId, 'revision_number' => 3, 'action' => 'EDIT']);
    }

    public function test_an_accepted_quotation_cannot_be_edited(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0009');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-0001')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/accept");

        $response = $this->actingAs($seller['owner'])->get("/quotations/{$quotationId}/edit");

        $response->assertOk();
        $response->assertSee('This quotation cannot be edited.');
        $response->assertDontSee('Save quotation revision');
    }

    /**
     * Closes the `quotation.converted` $plannedRoute placeholder: the
     * register's own ?status= filter (QuotationService::search) already
     * existed and was already tested server-side, but no Blade UI control
     * ever reached it. This exercises the real status dropdown added to
     * resources/views/quotations/index.blade.php, and the sidebar's own
     * "Converted Quotations" link now points at exactly this URL.
     */
    public function test_the_register_can_be_filtered_to_only_converted_quotations(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0010');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId, ['quotation_number' => 'QUO-VIEW-CONVERTED']));
        $convertedId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-CONVERTED')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$convertedId}/sending");
        $this->actingAs($seller['owner'])->post("/quotations/{$convertedId}/accept");
        $this->actingAs($seller['owner'])->post("/quotations/{$convertedId}/convert", [
            'invoice_number' => 'INV-FROM-VIEW-CONVERTED', 'issue_date' => '2026-09-02',
        ]);
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId, ['quotation_number' => 'QUO-VIEW-STILL-DRAFT']));

        $response = $this->actingAs($seller['owner'])->get('/quotations?status=CONVERTED');

        $response->assertOk();
        $response->assertSee('QUO-VIEW-CONVERTED');
        $response->assertSee('View invoice');
        $response->assertDontSee('QUO-VIEW-STILL-DRAFT');
    }

    /**
     * Closes the `quotation.converted-invoices` $plannedRoute placeholder.
     * Unlike Converted Quotations above, this one really was a gap: no
     * single query anywhere joined a converted quotation to its certified
     * invoice and every credit/debit note against that invoice. Exercises
     * the real join (QuotationService::crossReference) end to end,
     * including a real credit note raised through the existing invoice
     * correction path (POST /api/v1/invoices with document_type=CREDIT_NOTE).
     */
    public function test_the_cross_reference_page_shows_a_converted_quotations_invoice_and_its_credit_note(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0011');
        $customerPartyId = $this->createCustomerParty($seller['owner'], 'VAT-CUST-0011', 'Northgate Customer');
        $this->actingAs($seller['owner'])->post('/quotations', $this->quotationFormPayload($customerPartyId, ['quotation_number' => 'QUO-VIEW-XREF']));
        $quotationId = \App\Models\Quotation::where('quotation_number', 'QUO-VIEW-XREF')->firstOrFail()->id;
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/sending");
        $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/accept");
        $convert = $this->actingAs($seller['owner'])->post("/quotations/{$quotationId}/convert", [
            'invoice_number' => 'INV-VIEW-XREF', 'issue_date' => '2026-09-02',
        ]);
        $invoiceId = \App\Models\Invoice::where('invoice_number', 'INV-VIEW-XREF')->firstOrFail()->id;
        $sourceDocumentId = \App\Models\Invoice::findOrFail($invoiceId)->source_document_id;

        $creditNote = $this->actingAs($seller['owner'])->postJson('/api/v1/invoices', [
            'schema_version' => '1.0.0', 'invoice_number' => 'CN-VIEW-XREF', 'document_type' => 'CREDIT_NOTE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-cn-view-xref', 'submitted_at' => '2026-09-03T09:00:00Z'],
            'supplier' => ['name' => 'VAT-SELLER-0011 Trading Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SELLER-0011']]],
            'customer' => ['name' => 'Northgate Customer', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUST-0011']]],
            'issue_date' => '2026-09-03', 'currency' => 'NAD',
            'original_document_reference' => ['vat_msa_invoice_id' => $invoiceId, 'source_document_id' => $sourceDocumentId, 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.'],
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '-100.00', 'net_amount' => '-100.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-100.00', 'tax_amount' => '-15.00']]],
            'totals' => ['line_net_amount' => '-100.00', 'tax_exclusive_amount' => '-100.00', 'tax_amount' => '-15.00', 'tax_inclusive_amount' => '-115.00', 'payable_amount' => '-115.00'],
        ], ['Idempotency-Key' => 'test-idem-cn-view-xref']);
        $creditNote->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->get('/quotations/converted-invoices');

        $response->assertOk()->assertViewIs('quotations.converted-invoices');
        $response->assertSee('QUO-VIEW-XREF');
        $response->assertSee('Northgate Customer');
        $response->assertSee('INV-VIEW-XREF');
        $response->assertSee('CN-VIEW-XREF');
        $response->assertSee('Agreed pricing correction.');
    }

    public function test_the_cross_reference_page_shows_an_empty_state_when_nothing_has_been_converted(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0012');

        $response = $this->actingAs($seller['owner'])->get('/quotations/converted-invoices');

        $response->assertOk();
        $response->assertSee('No quotations have been converted to an invoice yet.');
    }

    /**
     * Backlog item #10's follow-up (docs/LAUNCH_READINESS_BACKLOG.md,
     * 2026-09-20): crossReference()'s own doc comment claims it batches
     * into a fixed number of queries regardless of row count -- checked
     * directly at a volume unmistakable enough that an unfixed N+1 (one
     * extra query per converted quotation) can't be missed. Eloquent-
     * creates the rows directly (matching BudgetsViewTest's own
     * precedent) rather than driving 40 real send/accept/convert HTTP
     * flows -- this test's own job is the read path's query count, not
     * re-exercising the write path already covered above.
     */
    public function test_the_cross_reference_page_query_count_does_not_scale_with_row_count(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLERNPLUS1-0001');
        $customerPartyId = $this->createCustomerParty($seller['owner']);
        for ($i = 0; $i < 40; $i++) {
            $invoiceId = (string) Str::uuid();
            \App\Models\Invoice::create([
                'id' => $invoiceId, 'invoice_number' => "INV-NPLUS1-{$i}", 'document_type' => 'TAX_INVOICE',
                'source_system' => 'test', 'source_document_id' => "doc-nplus1-{$i}",
                'supplier_taxpayer_id' => $seller['taxpayer']->id, 'supplier_name' => 'Seller', 'supplier_vat_number' => $seller['taxpayer']->vat_number,
                'customer_taxpayer_id' => null, 'customer_name' => 'Customer', 'customer_vat_number' => null,
                'issue_date' => now()->toDateString(), 'currency' => 'NAD',
                'line_net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
                'status' => 'CERTIFIED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "nplus1-{$i}"),
                'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                'verification_token' => 'vfy_nplus1_'.Str::random(20), 'created_at' => now(), 'certified_at' => now(),
            ]);
            \App\Models\Quotation::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $seller['organisation']->id, 'branch_id' => null,
                'customer_party_id' => $customerPartyId, 'quotation_number' => "QUO-NPLUS1-{$i}", 'currency' => 'NAD',
                'issue_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(), 'status' => 'CONVERTED',
                'subtotal_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'notes' => null,
                'created_by' => $seller['owner']->id, 'approved_by' => null, 'accepted_at' => now(),
                'converted_invoice_id' => $invoiceId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($i % 5 === 0) {
                $correctionInvoiceId = (string) Str::uuid();
                \App\Models\Invoice::create([
                    'id' => $correctionInvoiceId, 'invoice_number' => "CN-NPLUS1-{$i}", 'document_type' => 'CREDIT_NOTE',
                    'source_system' => 'test', 'source_document_id' => "doc-cn-nplus1-{$i}",
                    'supplier_taxpayer_id' => $seller['taxpayer']->id, 'supplier_name' => 'Seller', 'supplier_vat_number' => $seller['taxpayer']->vat_number,
                    'customer_taxpayer_id' => null, 'customer_name' => 'Customer', 'customer_vat_number' => null,
                    'issue_date' => now()->toDateString(), 'currency' => 'NAD',
                    'line_net_cents' => -20000, 'tax_cents' => -3000, 'total_cents' => -23000,
                    'status' => 'CERTIFIED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "cn-nplus1-{$i}"),
                    'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                    'verification_token' => 'vfy_cnnplus1_'.Str::random(20), 'created_at' => now(), 'certified_at' => now(),
                ]);
                \App\Models\InvoiceCorrection::create([
                    'id' => (string) Str::uuid(), 'original_invoice_id' => $invoiceId, 'correction_invoice_id' => $correctionInvoiceId,
                    'correction_type' => 'CREDIT_NOTE', 'reason_code' => 'PRICING_ERROR', 'reason' => 'N+1 volume test correction.',
                    'status' => 'ACTIVE', 'created_by' => $seller['owner']->id, 'created_at' => now(),
                ]);
            }
        }

        DB::enableQueryLog();
        $response = $this->actingAs($seller['owner'])->get('/quotations/converted-invoices');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(15, $queryCount, "Expected a small, row-count-independent query count; got {$queryCount} for 40 converted quotations -- an N+1 regression scales with row count, not a fixed ceiling.");
    }
}

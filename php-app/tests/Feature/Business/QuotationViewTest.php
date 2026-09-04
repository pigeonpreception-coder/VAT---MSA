<?php

namespace Tests\Feature\Business;

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

    private function createCustomerParty(User $owner, string $vatNumber = 'VAT-CUST-0001', string $displayName = 'Acme Customer'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/business-parties', [
            'schema_version' => '1.0.0', 'display_name' => $displayName, 'vat_number' => $vatNumber, 'relationships' => ['CUSTOMER'],
        ], ['Idempotency-Key' => 'test-idem-cust-'.$vatNumber]);

        return $response->json('resource.id');
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
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
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
}

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
 * Ported from lib/data/repository.ts's getPublicVerification and its two
 * source surfaces, app/api/v1/verify/[token]/route.ts (JSON) and
 * app/verify/[token]/page.tsx (Blade here). Deliberately unauthenticated
 * throughout -- no actingAs() anywhere in this file except the setup calls
 * that certify the fixture invoices.
 */
class PublicVerificationTest extends TestCase
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
            'invoice_number' => 'INV-VFY-0001',
            'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-vfy-0001', 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Verification Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-VFYSUP-0001']]],
            'customer' => ['name' => 'Verification Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-VFYCUS-0001']]],
            'issue_date' => '2026-09-01',
            'currency' => 'NAD',
            'lines' => [
                ['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']],
            ],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    /** @return array{invoiceId: string, token: string, supplier: array} */
    private function certifyInvoice(array $overrides = [], ?string $idempotencyKey = null): array
    {
        $supplier = $this->makeTradingParty('VAT-VFYSUP-0001');
        $this->makeTradingParty('VAT-VFYCUS-0001');

        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload($overrides), [
            'Idempotency-Key' => $idempotencyKey ?? 'test-idem-key-vfy-'.Str::random(8),
        ])->assertStatus(201);

        $invoiceId = $response->json('invoice_id');
        $token = Invoice::findOrFail($invoiceId)->verification_token;

        return ['invoiceId' => $invoiceId, 'token' => $token, 'supplier' => $supplier];
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $response = $this->getJson('/api/v1/verify/does-not-exist-token');

        $response->assertStatus(404)->assertJsonPath('title', 'Certificate not found');
    }

    public function test_a_certified_invoice_is_publicly_verifiable_without_authentication(): void
    {
        $fixture = $this->certifyInvoice();

        $response = $this->getJson("/api/v1/verify/{$fixture['token']}");

        $response->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate_status', 'VALID')
            ->assertJsonPath('supplier_display', 'Verification Supplier Co')
            ->assertJsonPath('invoice_number_masked', 'IN********01')
            ->assertJsonPath('total_amount', '1150.00')
            ->assertJsonPath('currency', 'NAD')
            ->assertJsonPath('is_correction', false)
            ->assertJsonPath('corrections', []);
        $response->assertJsonMissing(['invoice_number' => 'INV-VFY-0001']);
    }

    public function test_an_already_authenticated_user_can_also_reach_the_public_endpoint(): void
    {
        $other = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Unrelated Viewer', 'email' => 'unrelated-viewer@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        $fixture = $this->certifyInvoice();

        // Proves this route sits outside both the 'guest' and 'auth'
        // middleware groups -- a logged-in session must not be locked out.
        $response = $this->actingAs($other)->getJson("/api/v1/verify/{$fixture['token']}");

        $response->assertStatus(200)->assertJsonPath('valid', true);
    }

    public function test_a_credit_note_shows_up_on_both_sides_of_the_correction_lineage(): void
    {
        $original = $this->certifyInvoice();

        $creditNote = $this->invoicePayload([
            'invoice_number' => 'CN-VFY-0001', 'document_type' => 'CREDIT_NOTE',
            'source' => ['document_id' => 'doc-vfy-cn-0001'],
            'original_document_reference' => ['vat_msa_invoice_id' => $original['invoiceId'], 'source_document_id' => 'doc-vfy-0001', 'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.'],
        ]);
        $creditNote['lines'] = [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '-200.00', 'net_amount' => '-200.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-200.00', 'tax_amount' => '-30.00']]];
        $creditNote['totals'] = ['line_net_amount' => '-200.00', 'tax_exclusive_amount' => '-200.00', 'tax_amount' => '-30.00', 'tax_inclusive_amount' => '-230.00', 'payable_amount' => '-230.00'];
        $response = $this->actingAs($original['supplier']['owner'])->postJson('/api/v1/invoices', $creditNote, ['Idempotency-Key' => 'test-idem-key-vfy-cn-0001'])->assertStatus(201);
        $creditNoteToken = Invoice::findOrFail($response->json('invoice_id'))->verification_token;

        $originalVerification = $this->getJson("/api/v1/verify/{$original['token']}");
        $originalVerification->assertStatus(200)
            ->assertJsonPath('is_correction', false)
            ->assertJsonPath('corrections.0.correction_type', 'CREDIT_NOTE')
            ->assertJsonPath('corrections.0.invoice_number_masked', 'CN*******01')
            ->assertJsonPath('corrections.0.total_amount', '-230.00');

        $creditNoteVerification = $this->getJson("/api/v1/verify/{$creditNoteToken}");
        $creditNoteVerification->assertStatus(200)
            ->assertJsonPath('is_correction', true)
            ->assertJsonPath('correction_type', 'CREDIT_NOTE')
            ->assertJsonPath('corrects_invoice_number_masked', 'IN********01');
    }

    public function test_the_blade_verification_page_renders_for_a_valid_token(): void
    {
        $fixture = $this->certifyInvoice();

        $response = $this->get("/verify/{$fixture['token']}");

        $response->assertStatus(200)
            ->assertSee('Valid pilot certificate')
            ->assertSee('Verification Supplier Co')
            ->assertSee('IN********01');
        $response->assertDontSee('INV-VFY-0001');
    }

    public function test_the_blade_verification_page_404s_for_an_unknown_token(): void
    {
        $response = $this->get('/verify/does-not-exist-token');

        $response->assertStatus(404);
    }
}

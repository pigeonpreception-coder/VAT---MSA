<?php

namespace Tests\Feature\Identity;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the canonical taxpayer registry
 * (App\Http\Controllers\Identity\TaxpayerViewController /
 * resources/views/taxpayers/index.blade.php) -- ported from the source's
 * own app/taxpayers/page.tsx. Gap-finding pass (2026-09-23): this page had
 * no Laravel equivalent at all -- unlike the workspace-search gap, not
 * even the underlying `TaxpayerService::list()` read existed yet, and
 * there was no `GET /api/v1/taxpayers` JSON route either (matching
 * source, which never had one), confirmed by a repo-wide search finding
 * no `/taxpayers` route anywhere in `routes/web.php`.
 */
class TaxpayerViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation} */
    private function makeTaxpayer(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation');
    }

    public function test_the_taxpayer_registry_requires_authentication(): void
    {
        $this->get('/taxpayers')->assertRedirect('/login');
    }

    public function test_a_role_without_taxpayers_read_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-TPVIEW-DENY');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@tpview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/taxpayers')->assertForbidden();
    }

    public function test_the_registry_renders_real_taxpayers_with_capabilities_and_vat_totals(): void
    {
        $tp = $this->makeTaxpayer('VAT-TPVIEW-0001');
        OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'effective_to' => null,
        ]);
        $invoiceId = (string) Str::uuid();
        Invoice::create([
            'id' => $invoiceId, 'invoice_number' => 'INV-TPVIEW-0001', 'document_type' => 'TAX_INVOICE',
            'source_system' => 'test', 'source_document_id' => 'doc-tpview-0001',
            'supplier_taxpayer_id' => $tp['taxpayer']->id, 'supplier_name' => $tp['taxpayer']->legal_name,
            'supplier_vat_number' => $tp['taxpayer']->vat_number, 'customer_name' => 'Some Customer', 'issue_date' => '2026-09-01',
            'currency' => 'NAD', 'line_net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'status' => 'CERTIFIED',
            'risk_level' => 'LOW', 'payload_hash' => str_repeat('a', 64), 'transaction_id' => (string) Str::uuid(),
            'certificate_id' => (string) Str::uuid(), 'verification_token' => 'vfy_'.Str::random(32),
        ]);
        LedgerEntry::create([
            'id' => (string) Str::uuid(), 'transaction_id' => (string) Str::uuid(), 'invoice_id' => $invoiceId,
            'taxpayer_id' => $tp['taxpayer']->id, 'entry_type' => 'OUTPUT_VAT', 'direction' => 'CREDIT',
            'amount_cents' => 15000, 'period' => '2026-09', 'created_at' => now(),
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@tpview.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->get('/taxpayers');

        $response->assertOk()->assertViewIs('taxpayers.index');
        $response->assertSee('VAT-TPVIEW-0001 Trading Co');
        $response->assertSee('Seller');
        $response->assertSee('N$ 150.00');
    }
}

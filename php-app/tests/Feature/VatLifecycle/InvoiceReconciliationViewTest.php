<?php

namespace Tests\Feature\VatLifecycle;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Invoice Reconciliation Report
 * (App\Http\Controllers\VatLifecycle\InvoiceReconciliationViewController /
 * resources/views/vat-management/reconciliation.blade.php) -- the route
 * this replaces was a $plannedRoute stub until now (see routes/web.php's
 * own removed comment). Reuses VatLifecycleViewTest's own
 * makeTradingParty/certifyInvoice/openPeriod fixture pattern, since a real
 * category breakdown genuinely depends on certified-invoice lines, not
 * fixtures inserted directly.
 */
class InvoiceReconciliationViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
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

    /** Holds neither compliance:read nor any VAT-lifecycle permission -- the fully-denied fixture. */
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
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-'.Str::random(8), 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SUP-0001']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUS-0001']]],
            'issue_date' => '2026-09-01', 'currency' => 'NAD',
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']]],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    private function certifyInvoice(User $supplierOwner, string $supplierVat, string $customerVat): void
    {
        $response = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'test-idem-'.Str::random(20)]);
        $response->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
    }

    private function openPeriod(string $organisationId, string $taxpayerId, string $periodCode = '2026-09'): VatPeriod
    {
        return VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'taxpayer_id' => $taxpayerId,
            'period_code' => $periodCode, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->get('/vat-management/reconciliation')->assertRedirect('/login');
    }

    public function test_it_requires_the_compliance_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/vat-management/reconciliation')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_when_the_actor_has_no_vat_periods(): void
    {
        $party = $this->makeTradingParty('VAT-RECON-EMPTY-0001');

        $response = $this->actingAs($party['owner'])->get('/vat-management/reconciliation');

        $response->assertOk()->assertViewIs('vat-management.reconciliation');
        $response->assertSee('No VAT period is available to report on.');
    }

    public function test_it_breaks_down_certified_invoices_by_category_and_reconciles_against_the_filed_return(): void
    {
        $supplier = $this->makeTradingParty('VAT-RECON-SUP-0002');
        $customer = $this->makeTradingParty('VAT-RECON-CUS-0002');
        $this->certifyInvoice($supplier['owner'], 'VAT-RECON-SUP-0002', 'VAT-RECON-CUS-0002');
        $period = $this->openPeriod($customer['organisation']->id, $customer['taxpayer']->id);
        $this->actingAs($customer['owner'])->post(route('vat-periods.return.store', $period->id));
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();
        $this->assertSame(-15000, $version->net_payable_cents);

        $response = $this->actingAs($customer['owner'])->get(route('vat-management.reconciliation', ['period_id' => $period->id]));

        $response->assertOk()->assertViewIs('vat-management.reconciliation');
        $response->assertSee('Standard-Rated VAT');
        $response->assertSee('N$ 1,000.00'); // taxable amount
        $response->assertSee('N$ 150.00'); // VAT amount
        $response->assertSee('Reconciled');
        $response->assertDontSee('more than filed');
        $response->assertDontSee('less than filed');
    }

    public function test_a_later_certified_invoice_not_yet_reflected_in_the_filed_return_shows_as_a_discrepancy(): void
    {
        $supplier = $this->makeTradingParty('VAT-RECON-SUP-0003');
        $customer = $this->makeTradingParty('VAT-RECON-CUS-0003');
        $this->certifyInvoice($supplier['owner'], 'VAT-RECON-SUP-0003', 'VAT-RECON-CUS-0003');
        $period = $this->openPeriod($customer['organisation']->id, $customer['taxpayer']->id);
        $this->actingAs($customer['owner'])->post(route('vat-periods.return.store', $period->id));
        VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();

        // A second certified invoice for the same period, arriving after the
        // return was already generated -- the filed figures are now stale.
        // It adds another 150.00 of input VAT, pushing the computed net
        // payable to -300.00 (a bigger refund) against the filed -150.00 --
        // computed is numerically *less* than filed (-300.00 < -150.00).
        $this->certifyInvoice($supplier['owner'], 'VAT-RECON-SUP-0003', 'VAT-RECON-CUS-0003');

        $response = $this->actingAs($customer['owner'])->get(route('vat-management.reconciliation', ['period_id' => $period->id]));

        $response->assertOk();
        $response->assertSee('less than filed');
        $response->assertDontSee('Reconciled');
    }

    public function test_a_period_outside_the_actors_taxpayer_scope_403s_via_the_clean_error_page(): void
    {
        $party = $this->makeTradingParty('VAT-RECON-0004');
        $outsider = $this->makeTradingParty('VAT-RECON-OUT-0004');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($outsider['owner'])->get(route('vat-management.reconciliation', ['period_id' => $period->id]));

        $response->assertForbidden();
        $response->assertViewIs('errors.403');
    }
}

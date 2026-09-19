<?php

namespace Tests\Feature\VatLifecycle;

use App\Models\AuditCase;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\ReconciliationException;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the VAT Audit Report
 * (App\Http\Controllers\VatLifecycle\VatAuditReportViewController /
 * resources/views/vat-management/audit-report.blade.php) -- the route this
 * replaces was a $plannedRoute stub until now (see routes/web.php's own
 * removed comment). Reuses the same fixture conventions as
 * InvoiceReconciliationViewTest/VatAdjustmentReportViewTest.
 */
class VatAuditReportViewTest extends TestCase
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

    /** Certifies a real invoice (supplier -> customer) and returns its id. */
    private function certifyInvoice(User $supplierOwner, string $supplierVat, string $customerVat): string
    {
        $response = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'test-idem-'.Str::random(20)]);
        $response->assertStatus(201);

        return $response->json('invoice_id');
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
        $this->get('/vat-management/audit-report')->assertRedirect('/login');
    }

    public function test_it_requires_the_compliance_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/vat-management/audit-report')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_when_the_actor_has_no_vat_periods(): void
    {
        $party = $this->makeTradingParty('VAT-AUD-EMPTY-0001');

        $response = $this->actingAs($party['owner'])->get('/vat-management/audit-report');

        $response->assertOk()->assertViewIs('vat-management.audit-report');
        $response->assertSee('No VAT period is available to report on.');
    }

    public function test_it_summarizes_certified_invoices_by_risk_level(): void
    {
        $supplier = $this->makeTradingParty('VAT-AUD-SUP-0002');
        $customer = $this->makeTradingParty('VAT-AUD-CUS-0002');
        $this->certifyInvoice($supplier['owner'], 'VAT-AUD-SUP-0002', 'VAT-AUD-CUS-0002');
        $period = $this->openPeriod($supplier['organisation']->id, $supplier['taxpayer']->id);

        $response = $this->actingAs($supplier['owner'])->get(route('vat-management.audit-report', ['period_id' => $period->id]));

        $response->assertOk()->assertViewIs('vat-management.audit-report');
        $response->assertSee('Low'); // x-status-badge title-cases the raw risk_level value
        $response->assertSee('N$ 1,150.00'); // total value of the certified invoice
        $response->assertSee('MATCHED'); // status footer badge (plain, not through x-status-badge)
        $response->assertSee('No reconciliation exceptions raised in this period.');
        $response->assertSee('No audit cases opened against this taxpayer during this period.');
    }

    public function test_it_shows_a_reconciliation_exception_and_an_audit_case_raised_in_the_period(): void
    {
        $supplier = $this->makeTradingParty('VAT-AUD-SUP-0003');
        $customer = $this->makeTradingParty('VAT-AUD-CUS-0003');
        $invoiceId = $this->certifyInvoice($supplier['owner'], 'VAT-AUD-SUP-0003', 'VAT-AUD-CUS-0003');
        $period = $this->openPeriod($supplier['organisation']->id, $supplier['taxpayer']->id);

        ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'taxpayer_id' => $supplier['taxpayer']->id,
            'exception_type' => 'RISK_REVIEW', 'severity' => 'HIGH', 'status' => 'OPEN',
            'summary' => 'Invoice value significantly above the taxpayer\'s historical average.',
            'created_at' => '2026-09-05 10:00:00', 'resolved_at' => null,
        ]);
        AuditCase::create([
            'id' => (string) Str::uuid(), 'case_number' => 'CASE-2026-TEST0001', 'organisation_id' => $supplier['organisation']->id,
            'taxpayer_id' => $supplier['taxpayer']->id, 'case_type' => 'VAT_AUDIT', 'title' => 'Routine VAT audit',
            'opening_reason' => 'Selected for routine periodic VAT audit.', 'risk_tier' => 'MEDIUM', 'status' => 'PROPOSED',
            'assigned_officer_id' => null, 'opened_by' => $supplier['owner']->id, 'opened_at' => '2026-09-10 09:00:00', 'updated_at' => '2026-09-10 09:00:00',
        ]);

        $response = $this->actingAs($supplier['owner'])->get(route('vat-management.audit-report', ['period_id' => $period->id]));

        $response->assertOk();
        $response->assertSee('RISK_REVIEW');
        $response->assertSee('Invoice value significantly above the taxpayer\'s historical average.');
        $response->assertSee('CASE-2026-TEST0001');
        $response->assertSee('Routine VAT audit');
    }

    public function test_a_period_outside_the_actors_taxpayer_scope_403s_via_the_clean_error_page(): void
    {
        $party = $this->makeTradingParty('VAT-AUD-0004');
        $outsider = $this->makeTradingParty('VAT-AUD-OUT-0004');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($outsider['owner'])->get(route('vat-management.audit-report', ['period_id' => $period->id]));

        $response->assertForbidden();
        $response->assertViewIs('errors.403');
    }
}

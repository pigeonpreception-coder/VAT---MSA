<?php

namespace Tests\Feature\Dashboard;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Audit\AuditService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Dashboard\DashboardSnapshotService and the
 * DashboardController it replaces the earlier Session/effective-
 * permissions placeholder with -- ported from the source's own
 * app/page.tsx + lib/data/repository.ts's getDashboardSnapshot.
 */
class DashboardTest extends TestCase
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

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function pilotAdmin(string $email = 'pilot@dashboardtest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private static int $invoiceSeq = 0;

    private function makeInvoice(string $supplierTaxpayerId, ?string $customerTaxpayerId, int $totalCents, int $taxCents, string $status = 'CERTIFIED', string $riskLevel = 'LOW'): Invoice
    {
        $n = ++self::$invoiceSeq;

        return Invoice::create([
            'id' => (string) Str::uuid(), 'invoice_number' => "INV-DASH-{$n}", 'document_type' => 'TAX_INVOICE',
            'source_system' => 'TEST', 'source_document_id' => "src-dash-{$n}",
            'supplier_taxpayer_id' => $supplierTaxpayerId, 'supplier_name' => 'Test Supplier', 'supplier_vat_number' => 'VAT-TEST',
            'customer_taxpayer_id' => $customerTaxpayerId, 'customer_name' => 'Test Customer', 'customer_vat_number' => null,
            'issue_date' => now()->toDateString(), 'currency' => 'NAD',
            'line_net_cents' => $totalCents - $taxCents, 'tax_cents' => $taxCents, 'total_cents' => $totalCents,
            'status' => $status, 'risk_level' => $riskLevel, 'payload_hash' => hash('sha256', "inv-dash-{$n}"),
            'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(), 'verification_token' => Str::random(32),
            'created_at' => now(), 'certified_at' => now(),
        ]);
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_taxpayer_scoped_actor_sees_only_their_own_invoices_and_no_audit_trail(): void
    {
        $own = $this->makeTaxpayer('VAT-DASH-0001');
        $other = $this->makeTaxpayer('VAT-DASH-0002');
        $this->makeInvoice($own['taxpayer']->id, null, 100_000, 15_000, 'CERTIFIED', 'LOW');
        $this->makeInvoice($own['taxpayer']->id, null, 50_000, 7_500, 'EXCEPTION', 'HIGH');
        $this->makeInvoice($other['taxpayer']->id, null, 999_000, 99_000, 'CERTIFIED', 'CRITICAL');
        $owner = $this->taxpayerOwner($own['taxpayer']->id, 'owner@dashboardtest.test');

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertViewIs('dashboard');
        $snapshot = $response->viewData('snapshot');
        $this->assertSame(2, $snapshot['metrics']['invoice_count']);
        $this->assertSame(150_000, $snapshot['metrics']['total_cents']);
        $this->assertSame(22_500, $snapshot['metrics']['tax_cents']);
        $this->assertSame(1, $snapshot['metrics']['exception_count']);
        $this->assertCount(2, $snapshot['recent_invoices']);
        $this->assertTrue(collect($snapshot['recent_invoices'])->every(fn ($i) => $i['supplierName'] === 'Test Supplier'));
        // TAXPAYER_OWNER lacks audit:read -- the evidence stream stays empty regardless of real audit rows existing.
        $this->assertSame([], $snapshot['recent_audit']);
        $response->assertSee('VAT transaction control centre');
        $response->assertSee('Audit trail requires audit:read permission.');
    }

    public function test_a_national_actor_sees_every_taxpayers_invoices_and_the_real_audit_trail(): void
    {
        $tpA = $this->makeTaxpayer('VAT-DASH-0003');
        $tpB = $this->makeTaxpayer('VAT-DASH-0004');
        $this->makeInvoice($tpA['taxpayer']->id, null, 100_000, 15_000);
        $this->makeInvoice($tpB['taxpayer']->id, null, 200_000, 30_000);
        $admin = $this->pilotAdmin();
        // occurred_at is a whole-second MySQL TIMESTAMP -- explicit, distinct
        // instants avoid a same-second tie deciding the DESC ordering below.
        AuditService::append($admin, 'TEST_EVENT_ONE', 'INVOICE', 'inv-test-1', ['note' => 'first'], now()->subMinute());
        AuditService::append($admin, 'TEST_EVENT_TWO', 'INVOICE', 'inv-test-2', ['note' => 'second'], now());

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $snapshot = $response->viewData('snapshot');
        $this->assertSame(2, $snapshot['metrics']['invoice_count']);
        $this->assertSame(300_000, $snapshot['metrics']['total_cents']);
        $this->assertCount(2, $snapshot['recent_audit']);
        $this->assertSame('TEST_EVENT_TWO', $snapshot['recent_audit'][0]['action']);
        $response->assertSee('Test Event Two');
    }

    public function test_the_high_risk_footnote_counts_only_high_and_critical_invoices(): void
    {
        $tp = $this->makeTaxpayer('VAT-DASH-0005');
        $this->makeInvoice($tp['taxpayer']->id, null, 10_000, 1_000, 'EXCEPTION', 'LOW');
        $this->makeInvoice($tp['taxpayer']->id, null, 10_000, 1_000, 'EXCEPTION', 'MEDIUM');
        $this->makeInvoice($tp['taxpayer']->id, null, 10_000, 1_000, 'EXCEPTION', 'HIGH');
        $this->makeInvoice($tp['taxpayer']->id, null, 10_000, 1_000, 'EXCEPTION', 'CRITICAL');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'risk@dashboardtest.test');

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk()->assertSee('2 high or critical risk items');
    }

    public function test_a_customer_side_invoice_also_counts_toward_the_taxpayers_own_metrics(): void
    {
        $supplier = $this->makeTaxpayer('VAT-DASH-0006');
        $customer = $this->makeTaxpayer('VAT-DASH-0007');
        $this->makeInvoice($supplier['taxpayer']->id, $customer['taxpayer']->id, 40_000, 6_000);
        $customerOwner = $this->taxpayerOwner($customer['taxpayer']->id, 'customer@dashboardtest.test');

        $response = $this->actingAs($customerOwner)->get('/dashboard');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('snapshot')['metrics']['invoice_count']);
    }
}

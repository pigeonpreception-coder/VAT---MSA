<?php

namespace Tests\Feature\VatLifecycle;

use App\Models\ApprovalTask;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatAdjustment;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the VAT Adjustment Report
 * (App\Http\Controllers\VatLifecycle\VatAdjustmentReportViewController /
 * resources/views/vat-management/adjustment-report.blade.php) -- the route
 * this replaces was a $plannedRoute stub until now (see routes/web.php's
 * own removed comment). Reuses the same fixture conventions as
 * InvoiceReconciliationViewTest/VatLifecycleViewTest.
 */
class VatAdjustmentReportViewTest extends TestCase
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

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot-admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
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

    /** Certifies an original tax invoice and a credit note against it (supplier -> customer), both dated within the given period. Returns the credit note's invoice id. */
    private function certifyOriginalAndCreditNote(User $supplierOwner, string $supplierVat, string $customerVat): string
    {
        $original = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'orig-idem-'.Str::random(20)]);
        $original->assertStatus(201);
        $originalId = $original->json('invoice_id');
        $originalSourceDocId = $original->json('resource.source_document_id') ?? null;

        $creditNote = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'invoice_number' => 'CN-'.Str::random(8), 'document_type' => 'CREDIT_NOTE',
            'source' => ['document_id' => 'doc-cn-'.Str::random(8)],
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
            'issue_date' => '2026-09-02',
            'original_document_reference' => [
                'vat_msa_invoice_id' => $originalId, 'source_document_id' => $this->sourceDocIdOf($originalId),
                'reason_code' => 'PRICING_ERROR', 'reason' => 'Agreed pricing correction.',
            ],
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '-100.00', 'net_amount' => '-100.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '-100.00', 'tax_amount' => '-15.00']]],
            'totals' => ['line_net_amount' => '-100.00', 'tax_exclusive_amount' => '-100.00', 'tax_amount' => '-15.00', 'tax_inclusive_amount' => '-115.00', 'payable_amount' => '-115.00'],
        ]), ['Idempotency-Key' => 'cn-idem-'.Str::random(20)]);
        $creditNote->assertStatus(201);

        return $creditNote->json('invoice_id');
    }

    private function sourceDocIdOf(string $invoiceId): string
    {
        return \App\Models\Invoice::findOrFail($invoiceId)->source_document_id;
    }

    private function openPeriod(string $organisationId, string $taxpayerId, string $periodCode = '2026-09'): VatPeriod
    {
        return VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'taxpayer_id' => $taxpayerId,
            'period_code' => $periodCode, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Submits and approves a manual VAT adjustment for the given period, returning it fresh. */
    private function submitAndApproveAdjustment(User $owner, VatPeriod $period, string $amount = '50.00'): VatAdjustment
    {
        $this->actingAs($owner)->post(route('vat-periods.adjustments.store', $period->id), [
            'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount' => $amount,
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A supplier invoice arrived after period close.',
        ])->assertRedirect();
        $adjustment = VatAdjustment::where('vat_period_id', $period->id)->firstOrFail();
        $task = ApprovalTask::where('resource_type', 'VAT_ADJUSTMENT')->where('resource_id', $adjustment->id)->where('status', 'PENDING')->firstOrFail();
        $this->actingAs($this->pilotAdmin())->post(route('approval-tasks.decision.store', $task->id), [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ])->assertRedirect();

        return $adjustment->fresh();
    }

    public function test_it_requires_authentication(): void
    {
        $this->get('/vat-management/adjustment-report')->assertRedirect('/login');
    }

    public function test_it_requires_the_compliance_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/vat-management/adjustment-report')->assertForbidden();
    }

    public function test_it_shows_a_graceful_empty_state_when_the_actor_has_no_vat_periods(): void
    {
        $party = $this->makeTradingParty('VAT-ADJ-EMPTY-0001');

        $response = $this->actingAs($party['owner'])->get('/vat-management/adjustment-report');

        $response->assertOk()->assertViewIs('vat-management.adjustment-report');
        $response->assertSee('No VAT period is available to report on.');
    }

    /**
     * Deliberately does not generate a VAT return for this period: a
     * period containing only a credit note (no offsetting output invoice
     * of its own within the same period) trips VatLifecycleValidator::
     * checkedSum()'s per-entry non-negative guard in generateReturn() --
     * a genuine, pre-existing constraint of the return-generation pipeline
     * unrelated to this report, confirmed by reading that code path
     * directly. This test only exercises the report's own correction
     * display and its graceful "no return yet" state; the separate
     * reconciliation tests below cover the filed-return comparison using
     * adjustment-only periods that don't hit that constraint.
     */
    public function test_it_shows_the_credit_note_correction_in_the_output_section(): void
    {
        $supplier = $this->makeTradingParty('VAT-ADJ-SUP-0002');
        $customer = $this->makeTradingParty('VAT-ADJ-CUS-0002');
        $this->certifyOriginalAndCreditNote($supplier['owner'], 'VAT-ADJ-SUP-0002', 'VAT-ADJ-CUS-0002');
        $period = $this->openPeriod($supplier['organisation']->id, $supplier['taxpayer']->id);

        $response = $this->actingAs($supplier['owner'])->get(route('vat-management.adjustment-report', ['period_id' => $period->id]));

        $response->assertOk()->assertViewIs('vat-management.adjustment-report');
        $response->assertSee('Credit Note');
        $response->assertSee('N$ -100.00'); // credit note taxable amount
        $response->assertSee('N$ -15.00'); // credit note VAT amount
        $response->assertSee('No VAT return has been generated for this period yet.');
    }

    public function test_an_approved_adjustment_reconciles_cleanly_against_the_filed_return(): void
    {
        $supplier = $this->makeTradingParty('VAT-ADJ-SUP-0005');
        $period = $this->openPeriod($supplier['organisation']->id, $supplier['taxpayer']->id);
        $this->submitAndApproveAdjustment($supplier['owner'], $period);

        $this->actingAs($supplier['owner'])->post(route('vat-periods.return.store', $period->id))->assertRedirect();
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();
        $this->assertSame(5000, $version->adjustment_cents);

        $response = $this->actingAs($supplier['owner'])->get(route('vat-management.adjustment-report', ['period_id' => $period->id]));

        $response->assertOk()->assertViewIs('vat-management.adjustment-report');
        $response->assertSee('OUTPUT_TAX');
        $response->assertSee('N$ 50.00'); // approved adjustment amount
        $response->assertSee('Reconciled');
        $response->assertDontSee('more than filed');
        $response->assertDontSee('less than filed');
    }

    public function test_an_adjustment_approved_after_the_return_was_filed_shows_as_a_discrepancy(): void
    {
        $supplier = $this->makeTradingParty('VAT-ADJ-SUP-0003');
        $period = $this->openPeriod($supplier['organisation']->id, $supplier['taxpayer']->id);
        $this->actingAs($supplier['owner'])->post(route('vat-periods.return.store', $period->id))->assertRedirect();
        VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();

        // Filing locked the period, but an adjustment can still be
        // submitted/approved against it after the fact (matching the
        // platform's own real-world sequencing) -- so the filed
        // adjustment_cents (0) is now stale against the approved total.
        $this->submitAndApproveAdjustment($supplier['owner'], $period, '75.00');

        $response = $this->actingAs($supplier['owner'])->get(route('vat-management.adjustment-report', ['period_id' => $period->id]));

        $response->assertOk();
        $response->assertSee('more than filed');
    }

    public function test_a_period_outside_the_actors_taxpayer_scope_403s_via_the_clean_error_page(): void
    {
        $party = $this->makeTradingParty('VAT-ADJ-0004');
        $outsider = $this->makeTradingParty('VAT-ADJ-OUT-0004');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($outsider['owner'])->get(route('vat-management.adjustment-report', ['period_id' => $period->id]));

        $response->assertForbidden();
        $response->assertViewIs('errors.403');
    }
}

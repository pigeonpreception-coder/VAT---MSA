<?php

namespace Tests\Feature\VatLifecycle;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\TaxRuleSet;
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
 * Covers the real Blade UI for the VAT returns lifecycle
 * (App\Http\Controllers\VatLifecycle\VatLifecycleViewController /
 * resources/views/vat-periods/**, resources/views/vat-returns/**) --
 * the frontend UI build-out's third slice, after Dashboard and Invoices.
 * Reuses VatReturnLifecycleTest's own makeTradingParty/invoicePayload/
 * certifyInvoice/openPeriod fixture pattern, since a real return position
 * genuinely depends on certified-invoice ledger entries, not fixtures
 * inserted directly.
 */
class VatLifecycleViewTest extends TestCase
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

    private function makePilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot-admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither returns:read nor any VAT-lifecycle permission -- the fully-denied fixture. */
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

    private function certifyInvoice(User $supplierOwner, string $supplierVat, string $customerVat): void
    {
        $response = $this->actingAs($supplierOwner)->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplierVat]]],
            'customer' => ['identifiers' => [['value' => $customerVat]]],
        ]), ['Idempotency-Key' => 'test-idem-'.Str::random(20)]);
        $response->assertStatus(201)->assertJsonPath('processing_status', 'MATCHED');
    }

    private function openPeriod(string $organisationId, string $taxpayerId, string $status = 'OPEN', string $periodCode = '2026-09'): VatPeriod
    {
        return VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'taxpayer_id' => $taxpayerId,
            'period_code' => $periodCode, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => $status, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_vat_periods_list_requires_authentication(): void
    {
        $this->get('/vat-periods')->assertRedirect('/login');
    }

    public function test_the_vat_periods_list_requires_the_returns_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/vat-periods')->assertForbidden();
    }

    public function test_the_vat_periods_list_renders_an_open_period_with_a_working_link_to_its_detail_page(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0001');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($party['owner'])->get('/vat-periods');

        $response->assertOk()->assertViewIs('vat-periods.index');
        $response->assertSee('VAT periods');
        $response->assertSee($period['period_code']);
        $response->assertSee(route('vat-periods.show', $period->id), false);
    }

    public function test_the_period_detail_page_404s_for_a_period_outside_the_actors_taxpayer_scope(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0002');
        $outsider = $this->makeTradingParty('VAT-VIEW-OUT-0002');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $this->actingAs($outsider['owner'])->get("/vat-periods/{$period->id}")->assertNotFound();
    }

    public function test_generating_a_return_from_the_period_page_creates_a_draft_and_redirects_to_its_detail_page(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-0003');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-0003');
        $this->certifyInvoice($supplier['owner'], 'VAT-VIEW-SUP-0003', 'VAT-VIEW-CUS-0003');
        $period = $this->openPeriod($customer['organisation']->id, $customer['taxpayer']->id);

        $response = $this->actingAs($customer['owner'])->post(route('vat-periods.return.store', $period->id));

        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();
        $response->assertRedirect(route('vat-returns.show', $version->id));
        $this->assertSame('DRAFT', $version->status);
        $this->assertSame(15000, $version->input_tax_cents);

        $show = $this->actingAs($customer['owner'])->get(route('vat-returns.show', $version->id));
        $show->assertOk()->assertViewIs('vat-returns.show');
        $show->assertSee('Version 1');
        $show->assertSee('Output VAT');
        $show->assertSee('NAD 150.00'); // input VAT box amount
        $show->assertSee('Request approval');
    }

    public function test_a_permitted_user_can_submit_an_adjustment_which_shows_as_pending_on_the_period_page(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0004');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($party['owner'])->post(route('vat-periods.adjustments.store', $period->id), [
            'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount' => '250.00',
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A supplier invoice arrived after period close.',
        ]);

        $response->assertRedirect(route('vat-periods.show', $period->id));
        $response->assertSessionHas('status');
        $adjustment = VatAdjustment::where('vat_period_id', $period->id)->firstOrFail();
        $this->assertSame(25000, $adjustment->amount_cents);
        $this->assertSame('PENDING_APPROVAL', $adjustment->status);

        $show = $this->actingAs($party['owner'])->get(route('vat-periods.show', $period->id));
        $show->assertSee('LATE_INVOICE');
        $show->assertSee('NAD 250.00');
    }

    public function test_submitting_an_invalid_adjustment_shows_validation_errors_and_creates_no_row(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0005');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $response = $this->actingAs($party['owner'])->post(route('vat-periods.adjustments.store', $period->id), [
            'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount' => '250.00',
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'too short',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, VatAdjustment::where('vat_period_id', $period->id)->count());
    }

    public function test_requesting_approval_moves_the_return_to_pending_and_self_approval_is_a_friendly_form_error_not_a_403_page(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0006');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);
        $this->actingAs($party['owner'])->post(route('vat-periods.return.store', $period->id));
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();

        $request = $this->actingAs($party['owner'])->post(route('vat-returns.approval-request.store', $version->id));
        $request->assertRedirect(route('vat-returns.show', $version->id));
        $this->assertSame('PENDING_APPROVAL', $version->fresh()->status);

        $task = \App\Models\ApprovalTask::where('resource_id', $version->id)->where('status', 'PENDING')->firstOrFail();
        $selfDecide = $this->actingAs($party['owner'])->post(route('approval-tasks.decision.store', $task->id), [
            'decision' => 'APPROVE', 'comment' => 'Attempting to self-approve via the UI.',
        ]);

        // Caught inline and redirected back with a normal validation-style
        // error -- NOT the RT-002 clean-403 error page, which is reserved
        // for authorization failures the controller doesn't expect and
        // catch itself. See VatLifecycleViewController::decideApproval.
        $selfDecide->assertRedirect();
        $selfDecide->assertSessionHasErrors();
        $this->assertSame('PENDING', $task->fresh()->status);
    }

    public function test_a_pilot_admin_can_approve_a_pending_return_and_the_period_locks(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0007');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);
        $this->actingAs($party['owner'])->post(route('vat-periods.return.store', $period->id));
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();
        $this->actingAs($party['owner'])->post(route('vat-returns.approval-request.store', $version->id));
        $task = \App\Models\ApprovalTask::where('resource_id', $version->id)->where('status', 'PENDING')->firstOrFail();

        $approver = $this->makePilotAdmin();
        $decide = $this->actingAs($approver)->post(route('approval-tasks.decision.store', $task->id), [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ]);

        $decide->assertRedirect(route('vat-returns.show', $version->id));
        $this->assertSame('APPROVED', $version->fresh()->status);
        $this->assertSame('LOCKED', $period->fresh()->status);

        $show = $this->actingAs($party['owner'])->get(route('vat-returns.show', $version->id));
        $show->assertSee('Submit to ITAS');
    }

    public function test_submitting_an_approved_return_records_a_submission_attempt(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0008');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);
        $this->actingAs($party['owner'])->post(route('vat-periods.return.store', $period->id));
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();
        $this->actingAs($party['owner'])->post(route('vat-returns.approval-request.store', $version->id));
        $task = \App\Models\ApprovalTask::where('resource_id', $version->id)->where('status', 'PENDING')->firstOrFail();
        $this->actingAs($this->makePilotAdmin())->post(route('approval-tasks.decision.store', $task->id), [
            'decision' => 'APPROVE', 'comment' => 'Verified.',
        ]);

        $submit = $this->actingAs($party['owner'])->post(route('vat-returns.submission.store', $version->id));

        $submit->assertRedirect(route('vat-returns.show', $version->id));
        $submit->assertSessionHas('status');
        $this->assertDatabaseHas('vat_return_submissions', ['vat_return_version_id' => $version->id, 'provider' => 'ITAS']);
    }

    public function test_the_return_detail_page_403s_via_the_clean_error_page_for_a_version_outside_the_actors_taxpayer_scope(): void
    {
        $party = $this->makeTradingParty('VAT-VIEW-0009');
        $outsider = $this->makeTradingParty('VAT-VIEW-OUT-0009');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);
        $this->actingAs($party['owner'])->post(route('vat-periods.return.store', $period->id));
        $version = VatReturnVersion::where('vat_period_id', $period->id)->firstOrFail();

        $response = $this->actingAs($outsider['owner'])->get(route('vat-returns.show', $version->id));

        // VatLifecycleService::getVersionForActor throws AuthorizationException
        // (not a 404) for a cross-tenant version -- matches the JSON API's own
        // behaviour for this exact method, and gets the RT-002 clean-403 page.
        $response->assertForbidden();
        $response->assertViewIs('errors.403');
    }
}

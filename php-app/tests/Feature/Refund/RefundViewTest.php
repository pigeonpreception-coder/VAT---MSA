<?php

namespace Tests\Feature\Refund;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\RefundClaim;
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
 * Covers the real Blade UI for refund claims
 * (App\Http\Controllers\Refund\RefundViewController /
 * resources/views/refunds/**) -- the frontend UI build-out's fourth
 * slice, after Dashboard, Invoices, and VAT Returns. Reuses
 * RefundClaimTest's own makeTradingParty/makeRefundableReturn fixture
 * pattern, since a real refund claim genuinely depends on a certified-
 * invoice-backed, approved VAT return with a negative net position, not
 * a fixture inserted directly.
 */
class RefundViewTest extends TestCase
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

    private function makeRefundOfficer(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Refund Officer', 'email' => 'refund-officer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_REFUND_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function makePilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot-admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither refunds:read/request/review -- the fully-denied fixture. */
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

    /** Certifies a real invoice (supplier -> customer), then generates and approves a return for the customer's period -- a genuine negative-net-position (refund) return, read back from real ledger_entries. */
    private function makeRefundableReturn(array $supplier, array $customer, string $periodCode = '2026-09'): VatReturnVersion
    {
        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplier['taxpayer']->vat_number]]],
            'customer' => ['identifiers' => [['value' => $customer['taxpayer']->vat_number]]],
        ]), ['Idempotency-Key' => 'inv-'.Str::random(20)])->assertStatus(201);

        $period = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'period_code' => $periodCode, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $generate = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'gen-'.Str::random(20)]);
        $generate->assertStatus(201)->assertJsonPath('resource.net_payable_cents', -15000);
        $versionId = $generate->json('resource.id');

        $approvalRequest = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-returns/{$versionId}/approval-requests", [], ['Idempotency-Key' => 'ar-'.Str::random(20)]);
        $taskId = $approvalRequest->json('resource.id');
        $this->actingAs($this->makePilotAdmin())->postJson("/api/v1/approval-tasks/{$taskId}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ], ['Idempotency-Key' => 'decide-'.Str::random(20)])->assertStatus(200);

        return VatReturnVersion::findOrFail($versionId);
    }

    public function test_the_refunds_list_requires_authentication(): void
    {
        $this->get('/refunds')->assertRedirect('/login');
    }

    public function test_the_refunds_list_requires_the_refunds_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/refunds')->assertForbidden();
    }

    public function test_requesting_a_refund_from_the_return_page_shows_the_action_and_creates_a_real_claim(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2001');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2001');
        $version = $this->makeRefundableReturn($supplier, $customer);

        $returnPage = $this->actingAs($customer['owner'])->get(route('vat-returns.show', $version->id));
        $returnPage->assertSee('Request a refund');
        $returnPage->assertSee(route('vat-returns.refund-request.store', $version->id), false);

        // RefundService::request() only reaches RECEIVED when the return's
        // own status is 'FILED' -- confirmed by reading the whole codebase
        // that no application command anywhere ever sets that (submitReturn
        // updates the *submission* row's status, never the version's own),
        // matching RefundClaimTest's own identical workaround. Requesting
        // against the fixture as-is (still just APPROVED) is exercised on
        // its own below as the honestly-more-common real path.
        $response = $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();
        $response->assertRedirect(route('refunds.show', $claim->id));
        $this->assertSame('BLOCKED_RETURN_NOT_FILED', $claim->status);
        $this->assertSame(15000, $claim->amount_cents);
    }

    public function test_a_refund_claim_reaches_received_once_the_underlying_return_is_filed(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2001-B');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2001-B');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);

        $response = $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));

        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();
        $response->assertRedirect(route('refunds.show', $claim->id));
        $this->assertSame('RECEIVED', $claim->status);
    }

    public function test_the_refunds_list_renders_a_claim_with_a_working_link(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2002');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2002');
        $version = $this->makeRefundableReturn($supplier, $customer);
        $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();

        $response = $this->actingAs($customer['owner'])->get('/refunds');

        $response->assertOk()->assertViewIs('refunds.index');
        $response->assertSee($claim->claim_number);
        $response->assertSee(route('refunds.show', $claim->id), false);
    }

    public function test_the_refund_detail_page_404s_for_a_claim_outside_the_actors_taxpayer_scope(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2003');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2003');
        $outsider = $this->makeTradingParty('VAT-VIEW-OUT-2003');
        $version = $this->makeRefundableReturn($supplier, $customer);
        $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();

        $this->actingAs($outsider['owner'])->get(route('refunds.show', $claim->id))->assertNotFound();
    }

    public function test_the_refund_detail_page_shows_eligibility_checks_and_only_valid_actions_in_the_review_dropdown(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2004');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2004');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']); // see the FILED-status note in the first test above
        $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();

        $response = $this->actingAs($this->makeRefundOfficer())->get(route('refunds.show', $claim->id));

        $response->assertOk()->assertViewIs('refunds.show');
        $response->assertSee('Eligibility checks');
        $response->assertSee('Eligibility Negative Net Position');
        // RECEIVED only offers APPROVE/REJECT/REQUEST_INFORMATION/HOLD --
        // RESUME (an EVIDENCE_REQUESTED/ON_HOLD-only action) must not appear.
        $response->assertSeeInOrder(['Approve', 'Reject', 'Request Information', 'Hold']);
        $response->assertDontSee('>Resume<', false);
    }

    public function test_self_review_is_blocked_with_a_friendly_form_error_not_a_403_page(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2005');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2005');
        $version = $this->makeRefundableReturn($supplier, $customer);
        // The customer owner both requests the refund AND (implausibly, but
        // exactly what the self-review guard exists to catch) attempts to
        // review it -- refunds:review is officer-only in real RBAC, so this
        // uses a pilot admin acting as their own requester to force the
        // guard's own code path deterministically.
        $officer = $this->makePilotAdmin();
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']); // see the FILED-status note in the first test above
        $this->actingAs($officer)->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();

        $response = $this->actingAs($officer)->post(route('refunds.transition.store', $claim->id), [
            'action' => 'APPROVE', 'findings' => 'Attempting to review my own request.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertSame('RECEIVED', $claim->fresh()->status);
    }

    public function test_an_officer_can_reject_a_claim_and_the_original_requester_can_then_dispute_it(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2006');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2006');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']); // see the FILED-status note in the first test above
        $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));
        $claim = RefundClaim::where('vat_return_version_id', $version->id)->firstOrFail();

        $reject = $this->actingAs($this->makeRefundOfficer())->post(route('refunds.transition.store', $claim->id), [
            'action' => 'REJECT', 'findings' => 'Insufficient supporting evidence on file.',
        ]);
        $reject->assertRedirect(route('refunds.show', $claim->id));
        $this->assertSame('REJECTED', $claim->fresh()->status);

        $detail = $this->actingAs($customer['owner'])->get(route('refunds.show', $claim->id));
        $detail->assertSee('Dispute this outcome');

        $dispute = $this->actingAs($customer['owner'])->post(route('refunds.dispute.store', $claim->id), [
            'findings' => 'The evidence was in fact submitted; disputing this outcome.',
        ]);
        $dispute->assertRedirect(route('refunds.show', $claim->id));
        $this->assertSame('DISPUTED', $claim->fresh()->status);
    }

    public function test_a_duplicate_refund_request_shows_a_friendly_form_error_not_a_raw_json_body(): void
    {
        $supplier = $this->makeTradingParty('VAT-VIEW-SUP-2007');
        $customer = $this->makeTradingParty('VAT-VIEW-CUS-2007');
        $version = $this->makeRefundableReturn($supplier, $customer);
        $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));

        $duplicate = $this->actingAs($customer['owner'])->post(route('vat-returns.refund-request.store', $version->id));

        $duplicate->assertRedirect();
        $duplicate->assertSessionHasErrors('form');
        $this->assertSame(1, RefundClaim::where('vat_return_version_id', $version->id)->count());
    }
}

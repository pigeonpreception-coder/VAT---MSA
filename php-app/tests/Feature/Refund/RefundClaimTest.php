<?php

namespace Tests\Feature\Refund;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\RefundClaim;
use App\Models\TaxObligation;
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
 * Covers RefundService (ported from lib/data/compliance-repository.ts's
 * requestRefund/getRefundClaimChecks/transitionRefundClaim/disputeRefund)
 * over real HTTP against MySQL -- the refund workflow the VAT-return-
 * generation prerequisite was built specifically to unblock. Reuses
 * VatReturnLifecycleTest's own certify-a-real-invoice/generate-a-real-
 * return pattern so a refund claim is always anchored to a genuine
 * negative-net-position return, not a fixture inserted directly.
 */
class RefundClaimTest extends TestCase
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
    private function makeRefundableReturn(array $supplier, array $customer): VatReturnVersion
    {
        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplier['taxpayer']->vat_number]]],
            'customer' => ['identifiers' => [['value' => $customer['taxpayer']->vat_number]]],
        ]), ['Idempotency-Key' => 'inv-'.Str::random(20)])->assertStatus(201);

        $period = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
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

    public function test_requesting_a_refund_freezes_a_real_check_battery_and_rejects_a_duplicate_or_positive_position(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-2001');
        $customer = $this->makeTradingParty('VAT-CUS-2001');
        $version = $this->makeRefundableReturn($supplier, $customer);

        // The supplier's own period, generated from the very same certified
        // invoice, has a positive net position (output VAT only) -- a
        // refund request against it must be rejected outright.
        $supplierPeriod = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $supplier['organisation']->id, 'taxpayer_id' => $supplier['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierReturn = $this->actingAs($supplier['owner'])->postJson("/api/v1/vat-periods/{$supplierPeriod->id}/returns", [], ['Idempotency-Key' => 'sgen-'.Str::random(20)]);
        $supplierReturn->assertStatus(201)->assertJsonPath('resource.net_payable_cents', 15000);
        $positive = $this->actingAs($supplier['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $supplierReturn->json('resource.id'),
        ], ['Idempotency-Key' => 'positive-'.Str::random(20)]);
        $positive->assertStatus(409);

        $requestKey = 'refund-'.Str::random(20);
        $request = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => $requestKey]);
        $request->assertStatus(201)
            ->assertJsonPath('resource.status', 'BLOCKED_RETURN_NOT_FILED')
            ->assertJsonPath('resource.amount_cents', 15000)
            ->assertJsonPath('resource.evidence_status', 'AWAITING_ITAS_ACKNOWLEDGEMENT');
        $claimId = $request->json('resource.id');

        // Genuine idempotent replay (the *same* key) returns the identical claim, not a new one.
        $replay = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => $requestKey]);
        $replay->assertStatus(201)->assertJsonPath('resource.id', $claimId);
        $this->assertSame(1, RefundClaim::where('vat_return_version_id', $version->id)->count());

        // A *different* key against the same already-claimed return version is a real conflict.
        $duplicate = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund2-'.Str::random(20)]);
        $duplicate->assertStatus(409);

        $checks = $this->actingAs($customer['owner'])->getJson("/api/v1/refunds/{$claimId}/checks");
        $checks->assertStatus(200)->assertJsonCount(9, 'checks');
        $this->assertNotNull($checks->json('claim.claim_snapshot_hash'));
        $eligibilityCheck = collect($checks->json('checks'))->firstWhere('check_code', 'ELIGIBILITY_RETURN_FILED');
        // A genuine gap in the source, carried forward faithfully rather than
        // invented here: no application code path anywhere in either system
        // ever sets vat_return_versions.status to FILED (grepped and
        // confirmed before writing this test) -- see this migration's own
        // "Source-fidelity findings" note. So this check is honestly FAIL.
        $this->assertSame('FAIL', $eligibilityCheck['status']);

        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $customer['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($viewer)->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'viewer-'.Str::random(20)])->assertStatus(403);
    }

    public function test_the_full_transition_chain_reaches_payment_pending_with_a_distinct_officer_and_computes_the_debt_offset(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-2002');
        $customer = $this->makeTradingParty('VAT-CUS-2002');
        $version = $this->makeRefundableReturn($supplier, $customer);
        // Simulate the still-unbuilt "file the return" step by marking the
        // version FILED directly -- see the source-fidelity note above.
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);

        $request = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund-'.Str::random(20)]);
        $request->assertStatus(201)->assertJsonPath('resource.status', 'RECEIVED');
        $claimId = $request->json('resource.id');

        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'obligation_type' => 'INCOME_TAX', 'period_code' => '2026-09', 'due_date' => '2026-10-25',
            'amount_cents' => 3000, 'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'MANUAL',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $officer1 = $this->makeRefundOfficer();
        $officer2 = $this->makeRefundOfficer();

        // The requester cannot review their own claim.
        $this->actingAs($customer['owner'])->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Attempting to self-review.',
        ], ['Idempotency-Key' => 'self-'.Str::random(20)])->assertStatus(403);

        // A taxpayer-scoped actor (even with refunds:request) cannot transition at all.
        $this->actingAs($customer['owner'])->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'RECHECK_ELIGIBILITY', 'findings' => 'Not an officer.',
        ], ['Idempotency-Key' => 'notofficer-'.Str::random(20)])->assertStatus(403);

        $this->actingAs($officer1)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Risk screen clean.',
        ], ['Idempotency-Key' => 't1-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'RISK_REVIEW');
        $this->actingAs($officer1)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'No open risk indicators.',
        ], ['Idempotency-Key' => 't2-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'OFFICER_REVIEW');
        $this->actingAs($officer1)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Evidence in order, ready for payment authorisation.',
        ], ['Idempotency-Key' => 't3-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'PAYMENT_AUTHORISATION');

        // The material, fund-releasing APPROVE requires a genuinely distinct officer.
        $this->actingAs($officer1)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Attempting to also authorise payment.',
        ], ['Idempotency-Key' => 't4-'.Str::random(20)])->assertStatus(403);

        $final = $this->actingAs($officer2)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Independently verified; authorising payment.',
        ], ['Idempotency-Key' => 't5-'.Str::random(20)]);
        $final->assertStatus(200)
            ->assertJsonPath('resource.status', 'PAYMENT_PENDING')
            ->assertJsonPath('resource.approved_by', $officer2->id)
            ->assertJsonPath('resource.offset_amount_cents', 3000)
            ->assertJsonPath('resource.net_payable_cents', 12000);

        // PAYMENT_PENDING is a deliberate terminal boundary -- no further action is legal.
        $this->actingAs($officer2)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Attempting to go further.',
        ], ['Idempotency-Key' => 't6-'.Str::random(20)])->assertStatus(422);
    }

    public function test_request_information_and_hold_pause_the_claim_and_resume_returns_to_the_original_stage(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-2003');
        $customer = $this->makeTradingParty('VAT-CUS-2003');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);
        $claimId = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund-'.Str::random(20)])->json('resource.id');

        $officer = $this->makeRefundOfficer();
        $this->actingAs($officer)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'REQUEST_INFORMATION', 'findings' => 'Please supply the original tax invoice.',
        ], ['Idempotency-Key' => 'reqinfo-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'EVIDENCE_REQUESTED');
        $this->assertSame('RECEIVED', RefundClaim::findOrFail($claimId)->resume_status);

        $resumed = $this->actingAs($officer)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'RESUME', 'findings' => 'Evidence supplied, resuming review.',
        ], ['Idempotency-Key' => 'resume-'.Str::random(20)]);
        $resumed->assertStatus(200)->assertJsonPath('resource.status', 'RECEIVED')->assertJsonPath('resource.resume_status', null);
    }

    public function test_a_taxpayer_can_dispute_a_rejected_claim_but_not_another_taxpayers_and_an_upheld_dispute_closes_it(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-2004');
        $customer = $this->makeTradingParty('VAT-CUS-2004');
        $stranger = $this->makeTradingParty('VAT-SUP-2005');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);
        $claimId = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund-'.Str::random(20)])->json('resource.id');

        $officer = $this->makeRefundOfficer();
        $this->actingAs($officer)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'REJECT', 'findings' => 'Insufficient supporting evidence on file.',
        ], ['Idempotency-Key' => 'reject-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'REJECTED');

        $this->actingAs($stranger['owner'])->postJson("/api/v1/refunds/{$claimId}/disputes", [
            'schema_version' => '1.0.0', 'action' => 'DISPUTE', 'findings' => 'Not my claim.',
        ], ['Idempotency-Key' => 'stranger-'.Str::random(20)])->assertStatus(403);

        $dispute = $this->actingAs($customer['owner'])->postJson("/api/v1/refunds/{$claimId}/disputes", [
            'schema_version' => '1.0.0', 'action' => 'DISPUTE', 'findings' => 'The rejection did not consider the resubmitted evidence.',
        ], ['Idempotency-Key' => 'dispute-'.Str::random(20)]);
        $dispute->assertStatus(200)->assertJsonPath('resource.status', 'DISPUTED')->assertJsonPath('resource.dispute_reason', 'The rejection did not consider the resubmitted evidence.');

        $upheld = $this->actingAs($officer)->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'RESOLVE_DISPUTE_UPHOLD', 'findings' => 'Original rejection stands on review.',
        ], ['Idempotency-Key' => 'uphold-'.Str::random(20)]);
        $upheld->assertStatus(200)->assertJsonPath('resource.status', 'CLOSED');
    }
}

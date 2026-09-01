<?php

namespace Tests\Feature\VatLifecycle;

use App\Models\ApprovalTask;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\TaxRuleSet;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatAdjustment;
use App\Models\VatPeriod;
use App\Models\VatReturnSubmission;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers VatLifecycleService (ported from lib/data/vat-lifecycle-
 * repository.ts) over real HTTP against MySQL -- the VAT-return-generation
 * prerequisite Phase 9 deferred and Phase 11's refund slice was blocked on
 * (see docs/MIGRATION_MATRIX.md's Phase 9/11 rows). Reuses
 * InvoiceCertificationTest's own makeTradingParty/invoicePayload pattern to
 * certify real invoices first, so return generation reads genuine
 * `ledger_entries`, not fixtures inserted directly.
 */
class VatReturnLifecycleTest extends TestCase
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

    private function makeViewer(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
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

    public function test_a_certified_invoice_pair_generates_a_return_with_a_refund_position_for_the_customer_and_locks_the_period_on_approval(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-1001');
        $customer = $this->makeTradingParty('VAT-CUS-1001');
        $this->certifyInvoice($supplier['owner'], 'VAT-SUP-1001', 'VAT-CUS-1001');

        $period = $this->openPeriod($customer['organisation']->id, $customer['taxpayer']->id);

        $generate = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'gen-'.Str::random(20)]);
        $generate->assertStatus(201)
            ->assertJsonPath('resource.status', 'DRAFT')
            ->assertJsonPath('resource.version_number', 1)
            ->assertJsonPath('resource.output_tax_cents', 0)
            ->assertJsonPath('resource.input_tax_cents', 15000)
            ->assertJsonPath('resource.net_payable_cents', -15000);
        $versionId = $generate->json('resource.id');

        $detail = $this->actingAs($customer['owner'])->getJson("/api/v1/vat-returns/{$versionId}");
        $detail->assertStatus(200)->assertJsonCount(4, 'boxes');

        $approvalRequest = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-returns/{$versionId}/approval-requests", [], ['Idempotency-Key' => 'appreq-'.Str::random(20)]);
        $approvalRequest->assertStatus(202)->assertJsonPath('resource.status', 'PENDING');
        $taskId = $approvalRequest->json('resource.id');
        $this->assertSame('PENDING_APPROVAL', VatReturnVersion::findOrFail($versionId)->status);

        // Maker-checker: the requester themselves cannot decide their own task.
        $selfDecide = $this->actingAs($customer['owner'])->postJson("/api/v1/approval-tasks/{$taskId}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Attempting to self-approve.',
        ], ['Idempotency-Key' => 'self-'.Str::random(20)]);
        $selfDecide->assertStatus(403);

        $approver = $this->makePilotAdmin();
        $decide = $this->actingAs($approver)->postJson("/api/v1/approval-tasks/{$taskId}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ], ['Idempotency-Key' => 'decide-'.Str::random(20)]);
        $decide->assertStatus(200)->assertJsonPath('resource.status', 'APPROVED');

        $version = VatReturnVersion::findOrFail($versionId);
        $this->assertSame('APPROVED', $version->status);
        $lockedPeriod = VatPeriod::findOrFail($period->id);
        $this->assertSame('LOCKED', $lockedPeriod->status);
        $this->assertSame(1, $lockedPeriod->lock_version);
    }

    public function test_regenerating_a_draft_return_supersedes_it_but_a_controlled_version_blocks_generation_and_requires_an_open_period(): void
    {
        $party = $this->makeTradingParty('VAT-SUP-1002');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $lockedPeriod = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id, 'LOCKED', '2026-08');
        $blockedGenerate = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$lockedPeriod->id}/returns", [], ['Idempotency-Key' => 'blocked-'.Str::random(20)]);
        $blockedGenerate->assertStatus(409);

        $first = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g1-'.Str::random(20)]);
        $first->assertStatus(201)->assertJsonPath('resource.version_number', 1);
        $firstId = $first->json('resource.id');

        // Still DRAFT -- a second generation supersedes it and creates version 2.
        $second = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g2-'.Str::random(20)]);
        $second->assertStatus(201)->assertJsonPath('resource.version_number', 2);
        $this->assertSame('SUPERSEDED', VatReturnVersion::findOrFail($firstId)->status);

        $secondId = $second->json('resource.id');
        $this->actingAs($party['owner'])->postJson("/api/v1/vat-returns/{$secondId}/approval-requests", [], ['Idempotency-Key' => 'ar-'.Str::random(20)]);

        // Now v2 is PENDING_APPROVAL -- a third generation is blocked outright.
        $third = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g3-'.Str::random(20)]);
        $third->assertStatus(409);
    }

    public function test_a_different_taxpayer_cannot_read_or_act_on_another_taxpayers_return(): void
    {
        $owner = $this->makeTradingParty('VAT-SUP-1003');
        $stranger = $this->makeTradingParty('VAT-SUP-1004');
        $period = $this->openPeriod($owner['organisation']->id, $owner['taxpayer']->id);

        $generate = $this->actingAs($owner['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g-'.Str::random(20)]);
        $generate->assertStatus(201);
        $versionId = $generate->json('resource.id');

        $this->actingAs($stranger['owner'])->getJson("/api/v1/vat-returns/{$versionId}")->assertStatus(403);
        $this->actingAs($stranger['owner'])->postJson("/api/v1/vat-periods/{$period->id}/adjustments", [
            'schema_version' => '1.0.0', 'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount_cents' => 5000,
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A late invoice was discovered after the period closed.',
        ], ['Idempotency-Key' => 'adj-'.Str::random(20)])->assertStatus(403);
    }

    public function test_an_approved_vat_adjustment_feeds_the_next_generated_return_and_evidence_documents_are_rejected(): void
    {
        $party = $this->makeTradingParty('VAT-SUP-1005');
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $withEvidence = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/adjustments", [
            'schema_version' => '1.0.0', 'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount_cents' => 5000,
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A late invoice was discovered after the period closed.',
            'evidence_document_id' => 'doc-0001',
        ], ['Idempotency-Key' => 'adjev-'.Str::random(20)]);
        $withEvidence->assertStatus(422);

        $create = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/adjustments", [
            'schema_version' => '1.0.0', 'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount_cents' => 5000,
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A late invoice was discovered after the period closed.',
        ], ['Idempotency-Key' => 'adj-'.Str::random(20)]);
        $create->assertStatus(201)->assertJsonPath('resource.status', 'PENDING_APPROVAL');
        $adjustmentId = $create->json('resource.id');
        $task = ApprovalTask::where('resource_type', 'VAT_ADJUSTMENT')->where('resource_id', $adjustmentId)->firstOrFail();

        $approver = $this->makePilotAdmin();
        $this->actingAs($approver)->postJson("/api/v1/approval-tasks/{$task->id}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Confirmed against the late invoice evidence.',
        ], ['Idempotency-Key' => 'decide-'.Str::random(20)])->assertStatus(200);
        $this->assertSame('APPROVED', VatAdjustment::findOrFail($adjustmentId)->status);

        $generate = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g-'.Str::random(20)]);
        $generate->assertStatus(201)->assertJsonPath('resource.output_tax_cents', 5000)->assertJsonPath('resource.net_payable_cents', 5000);
    }

    public function test_generate_return_replay_is_idempotent_and_a_reused_key_across_periods_conflicts(): void
    {
        $party = $this->makeTradingParty('VAT-SUP-1006');
        $periodA = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id, 'OPEN', '2026-09');
        $periodB = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id, 'OPEN', '2026-10');

        $key = 'shared-'.Str::random(20);
        $first = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$periodA->id}/returns", [], ['Idempotency-Key' => $key]);
        $first->assertStatus(201);
        $replay = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$periodA->id}/returns", [], ['Idempotency-Key' => $key]);
        $replay->assertStatus(201)->assertJsonPath('resource.id', $first->json('resource.id'));
        $this->assertSame(1, VatReturnVersion::where('vat_period_id', $periodA->id)->count());

        $conflict = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$periodB->id}/returns", [], ['Idempotency-Key' => $key]);
        $conflict->assertStatus(409);
    }

    public function test_submission_is_blocked_while_the_rule_set_lacks_authority_approval_and_retries_safely_once_it_gains_it(): void
    {
        $party = $this->makeTradingParty('VAT-SUP-1007');
        $pilotPeriod = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id, 'OPEN', '2026-09');

        // Under the seeded PILOT_CONTROLLED rule, submission is blocked purely
        // by the local authority gate -- ITAS is never even attempted.
        $generate = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$pilotPeriod->id}/returns", [], ['Idempotency-Key' => 'g-'.Str::random(20)]);
        $versionId = $generate->json('resource.id');
        $this->approve($party, $versionId);
        $submit = $this->actingAs($party['owner'])->postJson("/api/v1/vat-returns/{$versionId}/submissions", [], ['Idempotency-Key' => 'sub-'.Str::random(20)]);
        $submit->assertStatus(202)
            ->assertJsonPath('resource.status', 'BLOCKED_CONFIGURATION')
            ->assertJsonPath('resource.last_error', 'Tax rule set lacks authority approval.');

        // A distinct AUTHORITY_APPROVED rule set (a later effective_from than
        // the seeded pilot one, so it wins the ORDER BY effective_from DESC
        // pick) lets generation reach the real ITAS call path, which this
        // migration's ITAS adapter always reports unavailable (see
        // ItasIdentityPort's own doc comment) -- BLOCKED_CONFIGURATION with a
        // different, ITAS-specific blocker message.
        TaxRuleSet::create([
            'id' => (string) Str::uuid(), 'jurisdiction' => 'NA', 'version' => 'NA-VAT-AUTHORITY-2026.1',
            'effective_from' => '2026-06-01', 'effective_to' => null, 'standard_rate_bps' => 1500,
            'status' => 'AUTHORITY_APPROVED', 'created_at' => now(),
        ]);
        $approvedPeriod = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id, 'OPEN', '2026-10');
        $generate2 = $this->actingAs($party['owner'])->postJson("/api/v1/vat-periods/{$approvedPeriod->id}/returns", [], ['Idempotency-Key' => 'g2-'.Str::random(20)]);
        $versionId2 = $generate2->json('resource.id');
        $this->approve($party, $versionId2);

        $submit1 = $this->actingAs($party['owner'])->postJson("/api/v1/vat-returns/{$versionId2}/submissions", [], ['Idempotency-Key' => 'sub1-'.Str::random(20)]);
        $submit1->assertStatus(202)
            ->assertJsonPath('resource.status', 'BLOCKED_CONFIGURATION')
            ->assertJsonPath('resource.last_error', 'ITAS technical contract and credentials are not configured.')
            ->assertJsonPath('resource.attempt_count', 1);
        $submissionId = $submit1->json('resource.id');

        // Retrying under a fresh idempotency key (a legitimate "try again"
        // action) must UPDATE the existing attempt in place, not collide
        // with the UNIQUE(provider, request_reference) constraint.
        $submit2 = $this->actingAs($party['owner'])->postJson("/api/v1/vat-returns/{$versionId2}/submissions", [], ['Idempotency-Key' => 'sub2-'.Str::random(20)]);
        $submit2->assertStatus(202)->assertJsonPath('resource.id', $submissionId)->assertJsonPath('resource.attempt_count', 2);
        $this->assertSame(1, VatReturnSubmission::where('vat_return_version_id', $versionId2)->count());
    }

    public function test_a_viewer_role_is_denied_generation_and_adjustment_commands(): void
    {
        $party = $this->makeTradingParty('VAT-SUP-1008');
        $viewer = $this->makeViewer($party['taxpayer']->id);
        $period = $this->openPeriod($party['organisation']->id, $party['taxpayer']->id);

        $this->actingAs($viewer)->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'g-'.Str::random(20)])->assertStatus(403);
        $this->actingAs($viewer)->postJson("/api/v1/vat-periods/{$period->id}/adjustments", [
            'schema_version' => '1.0.0', 'adjustment_type' => 'OUTPUT_TAX', 'direction' => 'INCREASE', 'amount_cents' => 5000,
            'reason_code' => 'LATE_INVOICE', 'explanation' => 'A late invoice was discovered after the period closed.',
        ], ['Idempotency-Key' => 'adj-'.Str::random(20)])->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/vat-periods')->assertStatus(200);
    }

    /** @param array{taxpayer: Taxpayer, organisation: Organisation, owner: User} $party */
    private function approve(array $party, string $versionId): void
    {
        $approvalRequest = $this->actingAs($party['owner'])->postJson("/api/v1/vat-returns/{$versionId}/approval-requests", [], ['Idempotency-Key' => 'ar-'.Str::random(20)]);
        $taskId = $approvalRequest->json('resource.id');
        $approver = $this->makePilotAdmin();
        $this->actingAs($approver)->postJson("/api/v1/approval-tasks/{$taskId}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ], ['Idempotency-Key' => 'decide-'.Str::random(20)])->assertStatus(200);
    }
}

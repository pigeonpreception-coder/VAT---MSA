<?php

namespace Tests\Feature\Compliance;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Compliance\ComplianceSnapshotService (ported from
 * lib/data/compliance-repository.ts's getComplianceSnapshot) -- Phase 11's
 * last remaining gap, the fixed-list dashboard aggregate every other
 * Phase 11 GET-list route bundles into. `refund_claims`/
 * `refund_claim_transitions` and `consent_grants`/`delegations` fixtures
 * are inserted directly rather than replayed through their own command
 * chains: the refund workflow and every other command exercised here
 * already have their own dedicated coverage (RefundClaimTest,
 * ComplianceCaseTest, CommunicationAndNotificationTest) -- this file's own
 * job is proving the snapshot's reads/joins are correct, not re-proving
 * those commands.
 */
class ComplianceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
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

    private function namraAuditor(string $email = 'auditor@snapshot.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@snapshot.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraComplianceOfficer(string $email = 'officer@snapshot.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Compliance Officer', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_COMPLIANCE_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    /** Advances a freshly opened case through AUTHORIZE/ASSIGN/ADVANCE* to the given target status, asserting 200 at every step. */
    private function advanceCaseTo(User $actor, string $caseId, string $targetStatus, string $keyPrefix): void
    {
        $order = ['PROPOSED', 'AUTHORIZED', 'ASSIGNED', 'PLANNING', 'EVIDENCE_COLLECTION', 'ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW', 'DECISION'];
        $steps = array_slice($order, 1, array_search($targetStatus, $order, true));
        foreach ($steps as $i => $status) {
            $action = $status === 'AUTHORIZED' ? 'AUTHORIZE' : ($status === 'ASSIGNED' ? 'ASSIGN' : 'ADVANCE');
            $payload = ['schema_version' => '1.0.0', 'action' => $action, 'reason' => "Advancing the case to {$status}."];
            if ($action === 'ASSIGN') {
                $payload['officer_id'] = $actor->id;
            }
            $this->actingAs($actor)->postJson("/api/v1/audit-cases/{$caseId}/transition", $payload, ['Idempotency-Key' => "{$keyPrefix}-{$i}"])->assertStatus(200);
        }
    }

    /**
     * Directly inserts a refund_claims + refund_claim_transitions row
     * (via a genuine vat_periods/vat_return_versions row, since the
     * snapshot's own JOIN requires both to resolve) -- see this class's
     * own doc comment for why this bypasses RequestRefund's command chain.
     *
     * @return array{claimId: string}
     */
    private function insertRefundFixture(Organisation $organisation, Taxpayer $taxpayer, User $actor): array
    {
        $periodId = (string) Str::uuid();
        VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id,
            'period_code' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = (string) Str::uuid();
        VatReturnVersion::create([
            'id' => $versionId, 'vat_period_id' => $periodId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 0, 'input_tax_cents' => 200000, 'adjustment_cents' => 0, 'net_payable_cents' => -200000,
            'status' => 'FILED', 'ledger_snapshot_hash' => str_repeat('c', 64), 'generated_by' => $actor->id, 'generated_at' => now(),
        ]);
        $claimId = (string) Str::uuid();
        $now = now();
        DB::table('refund_claims')->insert([
            'id' => $claimId, 'claim_number' => 'RFD-2026-'.mb_strtoupper(Str::random(8)), 'organisation_id' => $organisation->id,
            'taxpayer_id' => $taxpayer->id, 'vat_return_version_id' => $versionId, 'amount_cents' => 200000, 'currency' => 'NAD',
            'status' => 'RECEIVED', 'evidence_status' => 'PENDING_REVIEW', 'risk_tier' => 'MEDIUM', 'requested_by' => $actor->id,
            'requested_at' => $now, 'offset_amount_cents' => 0,
        ]);
        DB::table('refund_claim_transitions')->insert([
            'id' => (string) Str::uuid(), 'refund_claim_id' => $claimId, 'action' => 'RECEIVE', 'from_status' => 'RECEIVED',
            'to_status' => 'RECEIVED', 'actor_id' => $actor->id, 'findings' => 'Claim received and awaiting review.', 'occurred_at' => $now,
        ]);

        return ['claimId' => $claimId];
    }

    private function insertConsentAndDelegation(Organisation $organisation, Taxpayer $taxpayer, User $granter, User $delegate): array
    {
        $consentId = (string) Str::uuid();
        DB::table('consent_grants')->insert([
            'id' => $consentId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id, 'granted_by' => $granter->id,
            'grantee_type' => 'ROLE', 'grantee_id' => 'TAXPAYER_ACCOUNTANT', 'purpose' => 'VAT return preparation',
            'data_categories' => json_encode(['INVOICES', 'VAT_LEDGER']), 'legal_basis' => 'TAXPAYER_INSTRUCTION', 'status' => 'ACTIVE',
            'valid_from' => now()->subDay(), 'valid_to' => now()->addMonths(6), 'created_at' => now(),
        ]);
        $delegationId = (string) Str::uuid();
        DB::table('delegations')->insert([
            'id' => $delegationId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id,
            'delegator_user_id' => $granter->id, 'delegate_user_id' => $delegate->id, 'scopes' => json_encode(['returns:read']),
            'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => now()->addMonth(),
            'approved_by' => $granter->id, 'approved_at' => now(), 'created_at' => now(),
        ]);

        return ['consentId' => $consentId, 'delegationId' => $delegationId];
    }

    public function test_the_compliance_snapshot_aggregates_every_domain_for_a_national_actor(): void
    {
        $tp = $this->makeTaxpayer('VAT-SNAP-0001');
        $auditor = $this->namraAuditor();
        $supervisor = $this->namraSupervisor();
        $officer = $this->namraComplianceOfficer();
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@snapshot.test');

        $obligation = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 250000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-snap-obligation-0001'])->assertStatus(201)->json('resource.id');

        $caseId = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-snap-case-0001'])->assertStatus(201)->json('resource.id');

        $this->advanceCaseTo($auditor, $caseId, 'ANALYSIS', 'test-idem-snap-advance');
        $findingId = $this->actingAs($supervisor)->postJson("/api/v1/audit-cases/{$caseId}/findings", [
            'schema_version' => '1.0.0', 'finding_code' => 'UNDERSTATED-OUTPUT-VAT', 'title' => 'Understated output VAT',
            'description' => 'Output VAT for the period appears understated relative to certified invoice records.', 'amount_cents' => 500000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-snap-finding-0001'])->assertStatus(201)->json('resource.id');

        $disputeId = $this->actingAs($owner)->postJson('/api/v1/disputes', [
            'schema_version' => '1.0.0', 'audit_case_id' => $caseId, 'disputed_resource_type' => 'AUDIT_FINDING',
            'disputed_resource_id' => $findingId, 'grounds' => 'The finding relies on invoices that were subsequently corrected by credit note.',
            'disputed_amount_cents' => 500000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-snap-dispute-0001'])->assertStatus(201)->json('resource.id');

        \App\Models\TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-06', 'due_date' => '2026-07-25', 'amount_cents' => 100000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($auditor)->postJson("/api/v1/taxpayers/{$tp['taxpayer']->id}/risk-evaluation", ['schema_version' => '1.0.0'], ['Idempotency-Key' => 'test-idem-snap-risk-0001'])->assertStatus(200);
        $riskId = \App\Models\RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->where('indicator_code', 'OBLIGATION_OVERDUE')->firstOrFail()->id;

        $refund = $this->insertRefundFixture($tp['organisation'], $tp['taxpayer'], $auditor);

        $threadId = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'PORTAL', 'subject' => 'Request for supporting documentation', 'content_summary' => 'Please submit the invoices supporting the disputed period within 14 days.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-snap-notice-0001'])->assertStatus(201)->json('resource.id');

        $notificationId = $this->actingAs($officer)->postJson('/api/v1/notifications', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'notification_type' => 'FILING_REMINDER',
            'title' => 'VAT return due soon', 'message' => 'Your VAT return for the current period is due in 5 days.',
            'severity' => 'MEDIUM', 'channels' => ['IN_APP'],
        ], ['Idempotency-Key' => 'test-idem-snap-notif-0001'])->assertStatus(201)->json('resource.id');

        $grants = $this->insertConsentAndDelegation($tp['organisation'], $tp['taxpayer'], $owner, $auditor);

        $snapshot = $this->actingAs($auditor)->getJson('/api/v1/compliance');
        $snapshot->assertStatus(200);
        $body = $snapshot->json();

        foreach (['obligations', 'cases', 'findings', 'disputes', 'risks', 'refunds', 'refundTransitions', 'communications', 'notifications', 'consents', 'delegations'] as $key) {
            $this->assertArrayHasKey($key, $body, "snapshot is missing the '{$key}' key");
        }

        $this->assertTrue(collect($body['obligations'])->contains('id', $obligation));
        $this->assertTrue(collect($body['cases'])->contains('id', $caseId));
        $this->assertTrue(collect($body['findings'])->contains('id', $findingId));
        $this->assertTrue(collect($body['disputes'])->contains('id', $disputeId));
        $this->assertTrue(collect($body['risks'])->contains('id', $riskId));
        $foundRefund = collect($body['refunds'])->firstWhere('id', $refund['claimId']);
        $this->assertNotNull($foundRefund, 'refund claim missing from snapshot');
        $this->assertSame('2026-08', $foundRefund['period_code']);
        $this->assertSame(1, $foundRefund['version_number']);
        $this->assertTrue(collect($body['refundTransitions'])->contains('refund_claim_id', $refund['claimId']));
        $this->assertTrue(collect($body['communications'])->contains(fn ($c) => $c['related_resource_id'] === $caseId && $c['subject'] === 'Request for supporting documentation'));
        $this->assertTrue(collect($body['notifications'])->contains('id', $notificationId));
        $this->assertTrue(collect($body['consents'])->contains('id', $grants['consentId']));
        $this->assertTrue(collect($body['delegations'])->contains('id', $grants['delegationId']));

        // The national actor's unscoped notifications read carries every
        // notification (including the ones openAuditCase/createObligation/
        // fileDispute/evaluateRisk each write themselves), not just the one
        // explicitly queued above.
        $this->assertGreaterThan(1, count($body['notifications']));
    }

    public function test_the_compliance_snapshot_is_scoped_to_the_taxpayers_own_data(): void
    {
        $tpA = $this->makeTaxpayer('VAT-SNAP-0002');
        $tpB = $this->makeTaxpayer('VAT-SNAP-0003');
        $auditor = $this->namraAuditor();
        $ownerA = $this->taxpayerOwner($tpA['taxpayer']->id, 'owner-a@snapshot.test');

        $obligationA = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpA['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 100000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-snap-scope-oblig-a-0001'])->assertStatus(201)->json('resource.id');
        $obligationB = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpB['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 100000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-snap-scope-oblig-b-0001'])->assertStatus(201)->json('resource.id');

        $caseA = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpA['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Case A', 'opening_reason' => 'Risk pattern flagged for taxpayer A.', 'risk_tier' => 'MEDIUM',
        ], ['Idempotency-Key' => 'test-idem-snap-scope-case-a-0001'])->assertStatus(201)->json('resource.id');
        $caseB = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpB['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Case B', 'opening_reason' => 'Risk pattern flagged for taxpayer B.', 'risk_tier' => 'MEDIUM',
        ], ['Idempotency-Key' => 'test-idem-snap-scope-case-b-0001'])->assertStatus(201)->json('resource.id');

        $snapshot = $this->actingAs($ownerA)->getJson('/api/v1/compliance')->assertStatus(200);
        $body = $snapshot->json();

        $this->assertTrue(collect($body['obligations'])->contains('id', $obligationA));
        $this->assertFalse(collect($body['obligations'])->contains('id', $obligationB));
        $this->assertTrue(collect($body['cases'])->contains('id', $caseA));
        $this->assertFalse(collect($body['cases'])->contains('id', $caseB));
    }

    public function test_the_compliance_snapshot_requires_compliance_read_permission(): void
    {
        $analyst = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Security Analyst', 'email' => 'analyst@snapshot.test',
            'password' => bcrypt('password'), 'role' => 'SECURITY_ANALYST', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($analyst)->getJson('/api/v1/compliance')->assertStatus(403);
    }
}

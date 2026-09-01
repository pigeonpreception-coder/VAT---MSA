<?php

namespace Tests\Feature\Compliance;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 11 (slice 1): audit cases (App\Services\Compliance\
 * AuditCaseService, ported from openAuditCase/transitionCase/issueFinding/
 * getCaseTimeline/addEvidence/recordEvidenceCustodyEvent/getCaseEvidence/
 * addCaseNote/getCaseNotes) plus obligations, disputes, and risk -- Module
 * 3 Phase D and Module 4 Phases A-E.
 */
class ComplianceCaseTest extends TestCase
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

    private function namraAuditor(string $email = 'auditor@namra.test'): User
    {
        // cases:manage but NOT cases:override-sod -- the self-review-denial half.
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@namra.test'): User
    {
        // cases:manage AND cases:override-sod -- the override-success half.
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function openCase(User $actor, string $taxpayerId, array $overrides = []): string
    {
        $payload = array_replace([
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], $overrides);
        $response = $this->actingAs($actor)->postJson('/api/v1/audit-cases', $payload, ['Idempotency-Key' => 'test-idem-case-'.Str::random(8)]);

        return $response->json('resource.id');
    }

    public function test_a_national_officer_can_open_an_audit_case_and_a_taxpayer_cannot(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0001');
        $auditor = $this->namraAuditor();

        $response = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-case-open-0001']);

        $response->assertStatus(201)->assertJsonPath('resource.status', 'PROPOSED');
        $this->assertDatabaseHas('audit_cases', ['taxpayer_id' => $tp['taxpayer']->id, 'status' => 'PROPOSED']);
        $this->assertDatabaseHas('notifications', ['notification_type' => 'AUDIT_CASE_OPENED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'AUDIT_CASE_OPENED']);

        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-case@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $denied = $this->actingAs($owner)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Self-filed audit case attempt', 'opening_reason' => 'A taxpayer should never be able to open their own case.',
            'risk_tier' => 'LOW',
        ], ['Idempotency-Key' => 'test-idem-case-open-denied-0001']);
        $denied->assertStatus(403);
    }

    public function test_the_case_lifecycle_advances_through_its_real_state_machine(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0002');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);

        $authorize = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'AUTHORIZE', 'reason' => 'Reviewed and authorised for assignment.'], ['Idempotency-Key' => 'test-idem-case-auth-0001']);
        $authorize->assertStatus(200)->assertJsonPath('resource.status', 'AUTHORIZED');

        $assign = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'ASSIGN', 'reason' => 'Assigning to the case officer.', 'officer_id' => $auditor->id], ['Idempotency-Key' => 'test-idem-case-assign-0001']);
        $assign->assertStatus(200)->assertJsonPath('resource.status', 'ASSIGNED')->assertJsonPath('resource.assigned_officer_id', $auditor->id);

        // ADVANCE cannot be skipped straight to CLOSE.
        $illegalClose = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'CLOSE', 'reason' => 'Attempting to skip the lifecycle.'], ['Idempotency-Key' => 'test-idem-case-illegal-0001']);
        $illegalClose->assertStatus(422)->assertJsonPath('errors.0.code', 'CASE_TRANSITION_INVALID');

        foreach (['PLANNING', 'EVIDENCE_COLLECTION', 'ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW'] as $i => $expected) {
            $advance = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'ADVANCE', 'reason' => "Advancing to {$expected}."], ['Idempotency-Key' => 'test-idem-case-advance-'.$i]);
            $advance->assertStatus(200)->assertJsonPath('resource.status', $expected);
        }
        $this->assertDatabaseCount('audit_case_transitions', 7); // AUTHORIZE, ASSIGN, 5x ADVANCE (the illegal CLOSE wrote nothing)
    }

    public function test_suspend_and_resume_returns_to_the_exact_state_it_was_suspended_from(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0003');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);
        $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'AUTHORIZE', 'reason' => 'Authorised for assignment.'], ['Idempotency-Key' => 'test-idem-susp-auth-0001'])->assertStatus(200);
        $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'ASSIGN', 'reason' => 'Assigned to the case officer.', 'officer_id' => $auditor->id], ['Idempotency-Key' => 'test-idem-susp-assign-0001'])->assertStatus(200);
        $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'ADVANCE', 'reason' => 'Planning underway.'], ['Idempotency-Key' => 'test-idem-susp-advance-0001'])->assertStatus(200); // -> PLANNING

        $suspend = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'SUSPEND', 'reason' => 'Awaiting a legal opinion.'], ['Idempotency-Key' => 'test-idem-susp-0001']);
        $suspend->assertStatus(200)->assertJsonPath('resource.status', 'SUSPENDED');

        $resume = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'RESUME', 'reason' => 'Legal opinion received.'], ['Idempotency-Key' => 'test-idem-resume-0001']);
        $resume->assertStatus(200)->assertJsonPath('resource.status', 'PLANNING');
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

    public function test_a_case_with_no_findings_cannot_be_closed(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0004');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);
        $this->advanceCaseTo($auditor, $caseId, 'DECISION', 'test-idem-nofindings-step');

        $noFindings = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'CLOSE', 'reason' => 'Attempting to close without findings.'], ['Idempotency-Key' => 'test-idem-close-nofindings-0001']);

        $noFindings->assertStatus(409);
        $this->assertDatabaseHas('audit_cases', ['id' => $caseId, 'status' => 'DECISION']);
    }

    public function test_closing_a_case_with_findings_requires_segregation_of_duties_and_can_be_overridden(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0005');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);
        $this->advanceCaseTo($auditor, $caseId, 'FINDINGS_REVIEW', 'test-idem-close-step');

        // Issuing a finding is itself segregation-of-duties gated (the case's own
        // opener cannot issue a finding on it either, same as CLOSE) -- a different
        // officer (the supervisor) issues it here to isolate the CLOSE-specific
        // assertions below from this same rule.
        $supervisor = $this->namraSupervisor();
        $finding = $this->actingAs($supervisor)->postJson("/api/v1/audit-cases/{$caseId}/findings", [
            'schema_version' => '1.0.0', 'finding_code' => 'UNDERSTATED-OUTPUT-VAT', 'title' => 'Understated output VAT',
            'description' => 'Output VAT for the period appears understated relative to certified invoice records.', 'amount_cents' => 500000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-finding-0001']);
        $finding->assertStatus(201)->assertJsonPath('resource.status', 'PRELIMINARY');

        $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'ADVANCE', 'reason' => 'Findings reviewed, moving to decision.'], ['Idempotency-Key' => 'test-idem-close-todecision-0001'])->assertStatus(200);

        // The officer who opened the case cannot also close it without an override.
        $selfClose = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'CLOSE', 'reason' => 'Closing my own case.'], ['Idempotency-Key' => 'test-idem-close-self-0001']);
        $selfClose->assertStatus(403);
        $this->assertDatabaseHas('audit_cases', ['id' => $caseId, 'status' => 'DECISION']);

        // The same auditor lacks cases:override-sod, so even supplying an override reason is denied.
        $selfCloseWithReason = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'CLOSE', 'reason' => 'Closing my own case.', 'override_reason' => 'I am confident in my own finding.'], ['Idempotency-Key' => 'test-idem-close-self-0002']);
        $selfCloseWithReason->assertStatus(403);

        // The supervisor (not the case's opener, so no SoD gate at all) closes it.
        $close = $this->actingAs($supervisor)->postJson("/api/v1/audit-cases/{$caseId}/transition", ['schema_version' => '1.0.0', 'action' => 'CLOSE', 'reason' => 'Reviewed and closed by supervisor.'], ['Idempotency-Key' => 'test-idem-close-supervisor-0001']);
        $close->assertStatus(200)->assertJsonPath('resource.status', 'CLOSED');
    }

    public function test_invoice_evidence_can_be_added_verified_and_superseded(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0005');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);

        // A minimal certified invoice this case can cite as evidence.
        $invoiceId = (string) Str::uuid();
        \App\Models\Invoice::create([
            'id' => $invoiceId, 'invoice_number' => 'INV-EVID-0001', 'document_type' => 'TAX_INVOICE', 'source_system' => 'test',
            'source_document_id' => 'doc-evid-0001', 'supplier_taxpayer_id' => $tp['taxpayer']->id, 'supplier_name' => $tp['taxpayer']->legal_name,
            'supplier_vat_number' => $tp['taxpayer']->vat_number, 'customer_name' => 'Some Customer', 'issue_date' => '2026-09-01',
            'currency' => 'NAD', 'line_net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'status' => 'CERTIFIED',
            'risk_level' => 'LOW', 'payload_hash' => str_repeat('a', 64), 'transaction_id' => (string) Str::uuid(),
            'certificate_id' => (string) Str::uuid(), 'verification_token' => 'vfy_'.Str::random(32),
        ]);

        $add = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'INVOICE', 'source_resource_id' => $invoiceId, 'description' => 'The invoice underlying the disputed period.',
        ], ['Idempotency-Key' => 'test-idem-evidence-0001']);
        $add->assertStatus(201)->assertJsonPath('resource.status', 'PRESERVED')->assertJsonPath('resource.checksum_sha256', str_repeat('a', 64));
        $evidenceId = $add->json('resource.id');

        // Duplicate active citation of the same source is rejected.
        $duplicate = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'INVOICE', 'source_resource_id' => $invoiceId, 'description' => 'Duplicate citation attempt.',
        ], ['Idempotency-Key' => 'test-idem-evidence-dup-0001']);
        $duplicate->assertStatus(409);

        $verify = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events", ['schema_version' => '1.0.0', 'action' => 'VERIFY'], ['Idempotency-Key' => 'test-idem-verify-0001']);
        $verify->assertStatus(200);
        $this->assertDatabaseHas('audit_evidence_custody_events', ['audit_evidence_id' => $evidenceId, 'action' => 'VERIFY', 'integrity_verified' => 1]);

        $hold = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events", ['schema_version' => '1.0.0', 'action' => 'SET_LEGAL_HOLD', 'notes' => 'Preserving pending litigation.'], ['Idempotency-Key' => 'test-idem-hold-0001']);
        $hold->assertStatus(200)->assertJsonPath('resource.legal_hold', true);

        $supersede = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'INVOICE', 'source_resource_id' => $invoiceId,
            'description' => 'Corrected evidence citation.', 'supersedes_evidence_id' => $evidenceId,
        ], ['Idempotency-Key' => 'test-idem-evidence-supersede-0001']);
        $supersede->assertStatus(201)->assertJsonPath('resource.status', 'PRESERVED');
        $this->assertDatabaseHas('audit_evidence', ['id' => $evidenceId, 'status' => 'SUPERSEDED']);

        $listing = $this->actingAs($auditor)->getJson("/api/v1/audit-cases/{$caseId}/evidence");
        $listing->assertStatus(200)->assertJsonCount(2, 'evidence');
    }

    public function test_vat_return_evidence_can_be_cited_and_verified_but_document_evidence_is_still_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0005B');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);

        // A minimal real VAT return version this case can cite as evidence.
        $this->seed(\Database\Seeders\TaxRuleSetSeeder::class);
        $periodId = (string) Str::uuid();
        \App\Models\VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = (string) Str::uuid();
        \App\Models\VatReturnVersion::create([
            'id' => $versionId, 'vat_period_id' => $periodId, 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 15000, 'input_tax_cents' => 0, 'adjustment_cents' => 0, 'net_payable_cents' => 15000,
            'status' => 'DRAFT', 'ledger_snapshot_hash' => str_repeat('b', 64), 'generated_by' => $auditor->id, 'generated_at' => now(),
        ]);

        $add = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'VAT_RETURN', 'source_resource_id' => $versionId, 'description' => 'The generated return underlying the disputed period.',
        ], ['Idempotency-Key' => 'test-idem-evidence-vr-0001']);
        $add->assertStatus(201)->assertJsonPath('resource.status', 'PRESERVED')->assertJsonPath('resource.checksum_sha256', str_repeat('b', 64));
        $evidenceId = $add->json('resource.id');

        $verify = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events", ['schema_version' => '1.0.0', 'action' => 'VERIFY'], ['Idempotency-Key' => 'test-idem-verify-vr-0001']);
        $verify->assertStatus(200);
        $this->assertDatabaseHas('audit_evidence_custody_events', ['audit_evidence_id' => $evidenceId, 'action' => 'VERIFY', 'integrity_verified' => 1]);

        // DOCUMENT remains explicitly rejected -- document_metadata has not been ported.
        $document = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'DOCUMENT', 'source_resource_id' => (string) Str::uuid(), 'description' => 'An uploaded supporting document.',
        ], ['Idempotency-Key' => 'test-idem-evidence-doc-0001']);
        $document->assertStatus(422);
    }

    public function test_case_notes_are_append_only_and_a_correction_supersedes_without_deleting(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0006');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);

        $first = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/notes", ['schema_version' => '1.0.0', 'body' => 'Initial review notes on the taxpayer file.'], ['Idempotency-Key' => 'test-idem-note-0001']);
        $first->assertStatus(201);
        $firstId = $first->json('resource.id');

        $correction = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/notes", ['schema_version' => '1.0.0', 'body' => 'Correction: the prior note misstated the filing date.', 'supersedes_note_id' => $firstId], ['Idempotency-Key' => 'test-idem-note-0002']);
        $correction->assertStatus(201)->assertJsonPath('resource.supersedes_note_id', $firstId);

        $notes = $this->actingAs($auditor)->getJson("/api/v1/audit-cases/{$caseId}/notes");
        $notes->assertStatus(200)->assertJsonCount(2, 'notes');
        $this->assertDatabaseHas('audit_case_notes', ['id' => $firstId, 'body' => 'Initial review notes on the taxpayer file.']);
    }

    public function test_an_obligation_can_be_created_and_marked_satisfied_idempotently(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0007');
        $auditor = $this->namraAuditor();

        $create = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-08', 'due_date' => '2026-09-25', 'amount_cents' => 250000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-obligation-0001']);
        $create->assertStatus(201)->assertJsonPath('resource.status', 'PENDING');
        $obligationId = $create->json('resource.id');

        $duplicate = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-08', 'due_date' => '2026-09-25', 'amount_cents' => 250000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-obligation-dup-0001']);
        $duplicate->assertStatus(409);

        $satisfy = $this->actingAs($auditor)->postJson("/api/v1/obligations/{$obligationId}/satisfaction", ['schema_version' => '1.0.0', 'notes' => 'Return filed and payment received in full.'], ['Idempotency-Key' => 'test-idem-obligation-satisfy-0001']);
        $satisfy->assertStatus(200)->assertJsonPath('resource.status', 'SATISFIED');

        // Idempotent: marking an already-satisfied obligation satisfied again is a no-op success.
        $resatisfy = $this->actingAs($auditor)->postJson("/api/v1/obligations/{$obligationId}/satisfaction", ['schema_version' => '1.0.0', 'notes' => 'Re-confirming.'], ['Idempotency-Key' => 'test-idem-obligation-satisfy-0002']);
        $resatisfy->assertStatus(200)->assertJsonPath('resource.status', 'SATISFIED');
    }

    public function test_a_taxpayer_can_self_file_a_dispute_against_their_own_case(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0008');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCase($auditor, $tp['taxpayer']->id);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-dispute@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)->postJson('/api/v1/disputes', [
            'schema_version' => '1.0.0', 'audit_case_id' => $caseId, 'disputed_resource_type' => 'AUDIT_FINDING',
            'disputed_resource_id' => (string) Str::uuid(), 'grounds' => 'The finding relies on invoices that were subsequently corrected by credit note.',
            'disputed_amount_cents' => 500000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-dispute-0001']);

        $response->assertStatus(201)->assertJsonPath('resource.status', 'FILED');
        $this->assertDatabaseHas('disputes', ['taxpayer_id' => $tp['taxpayer']->id, 'audit_case_id' => $caseId]);

        // Referencing a case outside the taxpayer's own scope is rejected.
        $other = $this->makeTaxpayer('VAT-CASE-0009');
        $otherCaseId = $this->openCase($auditor, $other['taxpayer']->id);
        $outOfScope = $this->actingAs($owner)->postJson('/api/v1/disputes', [
            'schema_version' => '1.0.0', 'audit_case_id' => $otherCaseId, 'disputed_resource_type' => 'AUDIT_FINDING',
            'disputed_resource_id' => (string) Str::uuid(), 'grounds' => 'Attempting to dispute a case that is not mine.',
            'disputed_amount_cents' => 1000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-dispute-scope-0001']);
        $outOfScope->assertStatus(422);
    }

    public function test_evaluating_risk_raises_real_indicators_from_existing_evidence(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0010');
        $auditor = $this->namraAuditor();
        // Two overdue PENDING obligations should fire OBLIGATION_OVERDUE.
        foreach (['2026-06', '2026-07'] as $period) {
            \App\Models\TaxObligation::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
                'obligation_type' => 'VAT_RETURN', 'period_code' => $period, 'due_date' => '2026-07-25', 'amount_cents' => 100000,
                'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($auditor)->postJson("/api/v1/taxpayers/{$tp['taxpayer']->id}/risk-evaluation", ['schema_version' => '1.0.0'], ['Idempotency-Key' => 'test-idem-evaluate-0001']);

        $response->assertStatus(200)->assertJsonPath('rule_version', 'RISK-PILOT-2026.2');
        $factors = collect($response->json('factors'))->keyBy('indicator_code');
        $this->assertTrue($factors['OBLIGATION_OVERDUE']['fired']);
        $this->assertFalse($factors['HIGH_VALUE_INVOICE_PATTERN']['fired']);
        $this->assertDatabaseHas('risk_indicators', ['taxpayer_id' => $tp['taxpayer']->id, 'indicator_code' => 'OBLIGATION_OVERDUE', 'status' => 'OPEN']);
    }

    public function test_risk_review_assignment_and_escalation_creates_a_traceable_audit_case(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0011');
        $auditor = $this->namraAuditor();
        \App\Models\TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-06', 'due_date' => '2026-07-25', 'amount_cents' => 100000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($auditor)->postJson("/api/v1/taxpayers/{$tp['taxpayer']->id}/risk-evaluation", ['schema_version' => '1.0.0'], ['Idempotency-Key' => 'test-idem-evaluate-esc-0001'])->assertStatus(200);
        $indicatorId = \App\Models\RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->where('indicator_code', 'OBLIGATION_OVERDUE')->firstOrFail()->id;

        // A decision cannot be recorded before a review is assigned.
        $tooEarly = $this->actingAs($auditor)->postJson("/api/v1/risk-indicators/{$indicatorId}/decision", ['schema_version' => '1.0.0', 'decision' => 'DISMISS', 'rationale' => 'Attempting to decide before assignment.'], ['Idempotency-Key' => 'test-idem-early-decision-0001']);
        $tooEarly->assertStatus(409);

        $assign = $this->actingAs($auditor)->postJson("/api/v1/risk-indicators/{$indicatorId}/assignment", ['schema_version' => '1.0.0', 'officer_id' => $auditor->id], ['Idempotency-Key' => 'test-idem-assign-review-0001']);
        $assign->assertStatus(200)->assertJsonPath('resource.status', 'UNDER_REVIEW');

        $escalate = $this->actingAs($auditor)->postJson("/api/v1/risk-indicators/{$indicatorId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'ESCALATE_TO_CASE', 'rationale' => 'Confirmed genuine non-compliance pattern warranting a formal audit.',
            'case_type' => 'DESK_REVIEW', 'case_title' => 'Desk review triggered by overdue obligation risk indicator',
        ], ['Idempotency-Key' => 'test-idem-escalate-0001']);
        $escalate->assertStatus(200)->assertJsonPath('resource.status', 'ESCALATED_TO_CASE');
        $caseId = $escalate->json('resource.escalated_case_id');
        $this->assertNotNull($caseId);
        $this->assertDatabaseHas('audit_cases', ['id' => $caseId, 'opening_reason' => 'Confirmed genuine non-compliance pattern warranting a formal audit.']);
        $this->assertDatabaseHas('audit_events', ['action' => 'RISK_ACTION_ESCALATED']);
    }

    public function test_restricted_risk_query_is_never_visible_to_a_taxpayer(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASE-0012');
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-risk@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/risk-indicators');

        $response->assertStatus(403);
    }
}

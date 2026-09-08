<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditCase;
use App\Models\AuditEvidence;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Module 4 Phases C-D (audit cases) --
 * App\Http\Controllers\Compliance\AuditCaseViewController /
 * resources/views/audit-cases/** -- the frontend UI build-out's sixth
 * slice, after Dashboard, Invoices, VAT Returns, Refunds, and Risk
 * Indicators. Reuses ComplianceCaseTest's own makeTaxpayer/namraAuditor/
 * namraSupervisor/openCase/advanceCaseTo fixture pattern, since a real
 * case lifecycle genuinely depends on AuditCaseService's own state
 * machine and segregation-of-duties enforcement, not fixtures inserted
 * directly.
 */
class AuditCaseViewTest extends TestCase
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
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@namra.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds neither compliance:read nor cases:manage -- the fully-denied fixture. */
    private function developerPartner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'developer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function openCaseViaUi(User $actor, string $vatNumber): string
    {
        $response = $this->actingAs($actor)->post(route('audit-cases.store'), [
            'vat_number' => $vatNumber, 'case_type' => 'VAT_AUDIT', 'risk_tier' => 'HIGH',
            'title' => 'Suspected under-declaration of output VAT',
            'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
        ]);

        return AuditCase::where('taxpayer_id', Taxpayer::where('vat_number', $vatNumber)->firstOrFail()->id)->firstOrFail()->id;
    }

    /** Advances a freshly opened case through AUTHORIZE/ASSIGN/ADVANCE* to the given target status via the real Blade routes. */
    private function advanceCaseTo(User $actor, string $caseId, string $targetStatus): void
    {
        $order = ['PROPOSED', 'AUTHORIZED', 'ASSIGNED', 'PLANNING', 'EVIDENCE_COLLECTION', 'ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW', 'DECISION'];
        $steps = array_slice($order, 1, array_search($targetStatus, $order, true));
        foreach ($steps as $status) {
            $action = $status === 'AUTHORIZED' ? 'AUTHORIZE' : ($status === 'ASSIGNED' ? 'ASSIGN' : 'ADVANCE');
            $payload = ['action' => $action, 'reason' => "Advancing the case to {$status}."];
            if ($action === 'ASSIGN') {
                $payload['officer_id'] = $actor->id;
            }
            $this->actingAs($actor)->post(route('audit-cases.transition.store', $caseId), $payload)->assertRedirect(route('audit-cases.show', $caseId));
        }
    }

    public function test_the_audit_cases_list_requires_authentication(): void
    {
        $this->get('/audit-cases')->assertRedirect('/login');
    }

    public function test_the_audit_cases_list_requires_the_compliance_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/audit-cases')->assertForbidden();
    }

    public function test_opening_a_case_creates_a_real_record_and_redirects_to_it(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0001');
        $auditor = $this->namraAuditor();

        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0001');
        $case = AuditCase::findOrFail($caseId);
        $this->assertSame('PROPOSED', $case->status);

        $list = $this->actingAs($auditor)->get('/audit-cases');
        $list->assertOk()->assertViewIs('audit-cases.index');
        $list->assertSee($case->case_number);
        $list->assertSee('VAT-VIEW-CASE-0001 Trading Co');
    }

    public function test_a_taxpayer_cannot_open_a_case(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0002');

        $response = $this->actingAs($this->taxpayerOwner($tp['taxpayer']->id))->post(route('audit-cases.store'), [
            'vat_number' => 'VAT-VIEW-CASE-0002', 'case_type' => 'VAT_AUDIT', 'risk_tier' => 'LOW',
            'title' => 'Self-filed audit case attempt', 'opening_reason' => 'A taxpayer should never be able to open their own case.',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, AuditCase::where('taxpayer_id', $tp['taxpayer']->id)->count());
    }

    public function test_the_list_page_filters_by_status(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0003');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0003');
        $case = AuditCase::findOrFail($caseId);

        $matched = $this->actingAs($auditor)->get(route('audit-cases.index', ['status' => 'PROPOSED']));
        $matched->assertSee($case->case_number);

        $unmatched = $this->actingAs($auditor)->get(route('audit-cases.index', ['status' => 'CLOSED']));
        $unmatched->assertDontSee($case->case_number);
    }

    public function test_the_case_detail_page_shows_only_valid_actions_at_each_lifecycle_step(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0004');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0004');

        $proposed = $this->actingAs($auditor)->get(route('audit-cases.show', $caseId));
        $proposed->assertOk()->assertViewIs('audit-cases.show');
        $proposed->assertSee('Authorize');
        $proposed->assertDontSee('>Close<', false);

        $this->advanceCaseTo($auditor, $caseId, 'ASSIGNED');
        $assigned = $this->actingAs($auditor)->get(route('audit-cases.show', $caseId));
        $assigned->assertSee('Advance');
        $assigned->assertSee($auditor->name); // assigned officer now shown on the record
    }

    public function test_a_case_with_no_findings_cannot_be_closed_shows_a_friendly_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0005');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0005');
        $this->advanceCaseTo($auditor, $caseId, 'DECISION');

        $response = $this->actingAs($auditor)->post(route('audit-cases.transition.store', $caseId), [
            'action' => 'CLOSE', 'reason' => 'Attempting to close without findings.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertSame('DECISION', AuditCase::findOrFail($caseId)->status);
    }

    public function test_issuing_a_finding_and_closing_requires_a_distinct_officer_for_segregation_of_duties(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0006');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0006');
        $this->advanceCaseTo($auditor, $caseId, 'FINDINGS_REVIEW');

        // The case's own opener is also blocked from issuing a finding on it.
        $selfFinding = $this->actingAs($auditor)->post(route('audit-cases.findings.store', $caseId), [
            'finding_code' => 'SELF-FINDING', 'title' => 'Attempting self-review', 'description' => 'The opener should not be able to issue this finding.',
            'amount' => '100.00',
        ]);
        $selfFinding->assertSessionHasErrors('form');

        $supervisor = $this->namraSupervisor();
        $issueFinding = $this->actingAs($supervisor)->post(route('audit-cases.findings.store', $caseId), [
            'finding_code' => 'UNDERSTATED-OUTPUT-VAT', 'title' => 'Understated output VAT',
            'description' => 'Output VAT for the period appears understated relative to certified invoice records.', 'amount' => '5000.00',
        ]);
        $issueFinding->assertRedirect(route('audit-cases.show', $caseId));
        $this->assertDatabaseHas('audit_findings', ['audit_case_id' => $caseId, 'finding_code' => 'UNDERSTATED-OUTPUT-VAT', 'status' => 'PRELIMINARY']);

        $this->actingAs($auditor)->post(route('audit-cases.transition.store', $caseId), ['action' => 'ADVANCE', 'reason' => 'Findings reviewed, moving to decision.'])->assertRedirect();

        // The case's own opener cannot close it either, even with a reason (lacks cases:override-sod).
        $selfClose = $this->actingAs($auditor)->post(route('audit-cases.transition.store', $caseId), [
            'action' => 'CLOSE', 'reason' => 'Closing my own case.', 'override_reason' => 'I am confident in my own finding.',
        ]);
        $selfClose->assertSessionHasErrors('form');
        $this->assertSame('DECISION', AuditCase::findOrFail($caseId)->status);

        // A distinct officer (the supervisor, not the case's opener) closes it cleanly.
        $close = $this->actingAs($supervisor)->post(route('audit-cases.transition.store', $caseId), [
            'action' => 'CLOSE', 'reason' => 'Reviewed and closed by supervisor.',
        ]);
        $close->assertRedirect(route('audit-cases.show', $caseId));
        $this->assertSame('CLOSED', AuditCase::findOrFail($caseId)->status);
    }

    public function test_citing_evidence_and_recording_a_custody_event(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0007');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0007');

        $addEvidence = $this->actingAs($auditor)->post(route('audit-cases.evidence.store', $caseId), [
            'source_resource_type' => 'OTHER', 'source_resource_id' => 'ext-doc-001', 'description' => 'An externally supplied bank statement excerpt.',
            'checksum_sha256' => str_repeat('a', 64),
        ]);
        $addEvidence->assertRedirect(route('audit-cases.show', $caseId));
        $evidence = AuditEvidence::where('audit_case_id', $caseId)->firstOrFail();
        $this->assertSame('PRESERVED', $evidence->status);

        $show = $this->actingAs($auditor)->get(route('audit-cases.show', $caseId));
        $show->assertSee('ext-doc-001');

        $custody = $this->actingAs($auditor)->post(route('audit-evidence.custody-events.store', $evidence->id), [
            'action' => 'SET_LEGAL_HOLD', 'notes' => 'Preserving pending a formal document request.',
        ]);
        $custody->assertRedirect(route('audit-cases.show', $caseId));
        $this->assertTrue($evidence->fresh()->legal_hold);
    }

    public function test_adding_a_note(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0008');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0008');

        $response = $this->actingAs($auditor)->post(route('audit-cases.notes.store', $caseId), [
            'body' => 'Called the taxpayer to request supporting documentation.',
        ]);

        $response->assertRedirect(route('audit-cases.show', $caseId));
        $this->assertDatabaseHas('audit_case_notes', ['audit_case_id' => $caseId, 'body' => 'Called the taxpayer to request supporting documentation.']);

        $show = $this->actingAs($auditor)->get(route('audit-cases.show', $caseId));
        $show->assertSee('Called the taxpayer to request supporting documentation.');
    }

    public function test_a_taxpayer_can_view_their_own_case_read_only_but_cannot_transition_it(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0009');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0009');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);

        $show = $this->actingAs($owner)->get(route('audit-cases.show', $caseId));
        $show->assertOk();
        $show->assertDontSee('Record a decision');

        $attempt = $this->actingAs($owner)->post(route('audit-cases.transition.store', $caseId), ['action' => 'AUTHORIZE', 'reason' => 'A taxpayer should not be able to do this.']);
        $attempt->assertForbidden();
    }

    public function test_a_cross_tenant_taxpayer_gets_the_clean_403_page(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-CASE-0010');
        $outsider = $this->makeTaxpayer('VAT-VIEW-OUT-0010');
        $auditor = $this->namraAuditor();
        $caseId = $this->openCaseViaUi($auditor, 'VAT-VIEW-CASE-0010');

        $response = $this->actingAs($this->taxpayerOwner($outsider['taxpayer']->id))->get(route('audit-cases.show', $caseId));

        $response->assertForbidden();
        $response->assertViewIs('errors.403');
    }
}

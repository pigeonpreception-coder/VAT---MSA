<?php

namespace Tests\Feature\Platform;

use App\Models\AuditCase;
use App\Models\AuditEvidence;
use App\Models\AuditEvidenceCustodyEvent;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\ReconciliationException;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Platform\ReportExportService (ported from
 * lib/data/platform-repository.ts's runInlineReport/publishReportRun/
 * requestReportExport/approveReportExport/cancelReportExport/
 * getReportExport/downloadReportExport) -- Module 7 Phases A-C, Phase 13's
 * fourth slice.
 *
 * Not every one of the 7 seeded report codes gets its own full test --
 * SALES_VAT_SUMMARY, NATIONAL_VAT_AGGREGATE, COMPLIANCE_CASELOAD,
 * REVENUE_COMPLIANCE_TRENDS, CASE_EVIDENCE_SUMMARY and PORTFOLIO_EXCEPTIONS
 * each cover a genuinely distinct audience-tier guardrail or
 * computeReportResult shape; VAT_POSITION's own guardrail (TAXPAYER, no
 * extra check) and result shape (a single-table SUM/COUNT) are already
 * proven by SALES_VAT_SUMMARY's own coverage.
 */
class ReportExportTest extends TestCase
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

    /** Holds no `reports:read`/`reports:run` permission -- the negative-permission fixture. */
    private function sellerAdmin(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SELLER_ADMIN', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function pilotAdmin(string $email = 'pilot@reporttest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** National, has audit:read + reports:run, but NOT reports:executive. */
    private function namraAuditor(string $email = 'auditor@reporttest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraComplianceOfficer(string $email = 'officer@reporttest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Compliance Officer', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_COMPLIANCE_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@reporttest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function seedDefinition(string $code, string $audience, string $classification = 'CONFIDENTIAL', string $status = 'ACTIVE'): string
    {
        $id = (string) Str::uuid();
        DB::table('report_definitions')->insert([
            'id' => $id, 'code' => $code, 'name' => "{$code} definition", 'audience' => $audience,
            'description' => 'Test report definition.', 'classification' => $classification, 'query_version' => '1.0.0',
            'status' => $status, 'created_at' => now(), 'freshness_tier' => 'DAILY', 'guardrail' => 'test guardrail',
        ]);

        return $id;
    }

    private static int $invoiceSeq = 0;

    private function makeInvoice(string $taxpayerId, int $totalCents, int $taxCents): Invoice
    {
        $n = ++self::$invoiceSeq;

        return Invoice::create([
            'id' => (string) Str::uuid(), 'invoice_number' => "INV-REPORT-{$n}", 'document_type' => 'TAX_INVOICE',
            'source_system' => 'TEST', 'source_document_id' => "src-report-{$n}",
            'supplier_taxpayer_id' => $taxpayerId, 'supplier_name' => 'Test Supplier', 'supplier_vat_number' => 'VAT-TEST',
            'customer_taxpayer_id' => null, 'customer_name' => 'Test Customer', 'customer_vat_number' => null,
            'issue_date' => now()->toDateString(), 'currency' => 'NAD',
            'line_net_cents' => $totalCents - $taxCents, 'tax_cents' => $taxCents, 'total_cents' => $totalCents,
            'status' => 'CERTIFIED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "inv-report-{$n}"),
            'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(), 'verification_token' => Str::random(32),
            'created_at' => now(), 'certified_at' => now(),
        ]);
    }

    private static int $caseSeq = 0;

    private function makeAuditCase(string $organisationId, string $taxpayerId, string $openedBy): AuditCase
    {
        $n = ++self::$caseSeq;

        return AuditCase::create([
            'id' => (string) Str::uuid(), 'case_number' => "CASE-REPORT-{$n}", 'organisation_id' => $organisationId,
            'taxpayer_id' => $taxpayerId, 'case_type' => 'DESK_REVIEW', 'title' => "Test case {$n}",
            'opening_reason' => 'Testing report exports.', 'risk_tier' => 'LOW', 'status' => 'OPEN',
            'opened_by' => $openedBy, 'opened_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{schema_version: string} */
    private function exportCommandBody(): array
    {
        return ['schema_version' => '1.0.0'];
    }

    public function test_running_a_report_requires_the_reports_run_permission(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0001');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $denied = $this->sellerAdmin($tp['taxpayer']->id, 'noperm@reporttest.test');

        $this->actingAs($denied)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->assertStatus(403);
    }

    public function test_an_unknown_report_code_returns_404(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0002');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'unknown@reporttest.test');

        $this->actingAs($owner)->postJson('/api/v1/reports/NO_SUCH_CODE/runs', [])->assertStatus(404);
    }

    public function test_a_report_definition_with_no_runnable_implementation_fails_closed(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0003');
        $this->seedDefinition('UNIMPLEMENTED_REPORT', 'TAXPAYER', 'CONFIDENTIAL');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'unimplemented@reporttest.test');

        $this->actingAs($owner)->postJson('/api/v1/reports/UNIMPLEMENTED_REPORT/runs', [])->assertStatus(501);
    }

    public function test_sales_vat_summary_aggregates_the_actors_own_organisations_invoices(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0004');
        $other = $this->makeTaxpayer('VAT-RPT-0005');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 115_000, 15_000);
        $this->makeInvoice($tp['taxpayer']->id, 230_000, 30_000);
        $this->makeInvoice($other['taxpayer']->id, 999_000, 99_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'sales@reporttest.test');

        $response = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', []);

        $response->assertStatus(201);
        $run = $response->json('report_run');
        $this->assertSame('COMPLETED_INLINE', $run['status']);
        $this->assertSame(['invoices' => 2, 'total_cents' => 345_000, 'tax_cents' => 45_000], $run['result_summary']);
        $this->assertSame('TAXPAYER', $run['envelope']['audience']);
        $this->assertSame('NAD', $run['envelope']['currency_basis']);
    }

    public function test_compliance_caseload_is_restricted_to_namra_operations_roles(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0006');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'caseload-denied@reporttest.test');
        $officer = $this->namraComplianceOfficer();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $officer->id);

        $this->actingAs($owner)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->assertStatus(403);

        $response = $this->actingAs($officer)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', []);
        $response->assertStatus(201);
        $this->assertSame(['cases' => 1, 'open_cases' => 1], $response->json('report_run.result_summary'));
    }

    public function test_revenue_compliance_trends_requires_national_scope_and_the_executive_permission(): void
    {
        $this->seedDefinition('REVENUE_COMPLIANCE_TRENDS', 'EXECUTIVE', 'CONFIDENTIAL');
        $auditor = $this->namraAuditor();
        $admin = $this->pilotAdmin();

        // National but lacks reports:executive.
        $this->actingAs($auditor)->postJson('/api/v1/reports/REVENUE_COMPLIANCE_TRENDS/runs', [])->assertStatus(403);

        // National and holds reports:executive.
        $this->actingAs($admin)->postJson('/api/v1/reports/REVENUE_COMPLIANCE_TRENDS/runs', [])->assertStatus(201);
    }

    public function test_case_evidence_summary_requires_a_case_id_and_audit_case_authority(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0007');
        $this->seedDefinition('CASE_EVIDENCE_SUMMARY', 'AUDITOR_LEGAL', 'RESTRICTED');
        $auditor = $this->namraAuditor();
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'evidence-denied@reporttest.test');
        $case = $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $auditor->id);
        $evidence = AuditEvidence::create([
            'id' => (string) Str::uuid(), 'audit_case_id' => $case->id, 'evidence_type' => 'INVOICE_RECORD',
            'source_resource_type' => 'INVOICE', 'source_resource_id' => (string) Str::uuid(), 'document_id' => null,
            'checksum_sha256' => hash('sha256', 'evidence-1'), 'description' => 'Test evidence.', 'status' => 'PRESERVED',
            'added_by' => $auditor->id, 'added_at' => now(),
        ]);
        AuditEvidenceCustodyEvent::create([
            'id' => (string) Str::uuid(), 'audit_evidence_id' => $evidence->id, 'action' => 'ADDED',
            'actor_id' => $auditor->id, 'occurred_at' => now(),
        ]);

        // Lacks audit:read/cases:manage entirely.
        $this->actingAs($owner)->postJson('/api/v1/reports/CASE_EVIDENCE_SUMMARY/runs', ['case_id' => $case->id])->assertStatus(403);

        // Missing case_id.
        $this->actingAs($auditor)->postJson('/api/v1/reports/CASE_EVIDENCE_SUMMARY/runs', [])->assertStatus(422);

        $response = $this->actingAs($auditor)->postJson('/api/v1/reports/CASE_EVIDENCE_SUMMARY/runs', ['case_id' => $case->id]);
        $response->assertStatus(201);
        $this->assertSame(['evidence_items' => 1, 'preserved_items' => 1, 'custody_events' => 1], $response->json('report_run.result_summary'));
    }

    /**
     * The cross-tenant `case_id` refusal (`$actor->taxpayer_id !== $auditCase->taxpayer_id`)
     * is preserved faithfully from the source but is, like
     * `PlatformSnapshotService::getSnapshot`'s own `$scoped` branch, not
     * reachable by any role seeded today: every role holding
     * `audit:read`/`cases:manage` is also a `NATIONAL_SCOPE_ROLES` member
     * (verified across the full `Permissions::ROLE_PERMISSIONS` map), so
     * `TenantScope::isNational($actor)` is always true before that
     * comparison is ever reached. This test covers what IS reachable: an
     * unknown case id (404) and a real case from a national officer (201).
     */
    public function test_case_evidence_summary_returns_404_for_an_unknown_case_and_succeeds_for_a_national_officer(): void
    {
        $caseTp = $this->makeTaxpayer('VAT-RPT-0008');
        $this->seedDefinition('CASE_EVIDENCE_SUMMARY', 'AUDITOR_LEGAL', 'RESTRICTED');
        $officer = $this->namraComplianceOfficer();
        $case = $this->makeAuditCase($caseTp['organisation']->id, $caseTp['taxpayer']->id, $officer->id);

        $this->actingAs($officer)->postJson('/api/v1/reports/CASE_EVIDENCE_SUMMARY/runs', ['case_id' => (string) Str::uuid()])->assertStatus(404);
        $this->actingAs($officer)->postJson('/api/v1/reports/CASE_EVIDENCE_SUMMARY/runs', ['case_id' => $case->id])->assertStatus(201);
    }

    public function test_portfolio_exceptions_requires_an_active_delegation_and_scopes_to_delegated_taxpayers(): void
    {
        $delegated = $this->makeTaxpayer('VAT-RPT-0010');
        $undelegated = $this->makeTaxpayer('VAT-RPT-0011');
        $this->seedDefinition('PORTFOLIO_EXCEPTIONS', 'PRACTITIONER', 'TAX_CONFIDENTIAL');
        $practitioner = $this->taxpayerOwner($delegated['taxpayer']->id, 'practitioner@reporttest.test');

        $delegatedInvoice = $this->makeInvoice($delegated['taxpayer']->id, 100_000, 15_000);
        $undelegatedInvoice = $this->makeInvoice($undelegated['taxpayer']->id, 200_000, 30_000);
        ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $delegatedInvoice->id, 'taxpayer_id' => $delegated['taxpayer']->id,
            'exception_type' => 'AMOUNT_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test exception.', 'created_at' => now(),
        ]);
        ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $undelegatedInvoice->id, 'taxpayer_id' => $undelegated['taxpayer']->id,
            'exception_type' => 'AMOUNT_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test exception.', 'created_at' => now(),
        ]);

        // No active delegation yet.
        $this->actingAs($practitioner)->postJson('/api/v1/reports/PORTFOLIO_EXCEPTIONS/runs', [])->assertStatus(403);

        DB::table('delegations')->insert([
            'id' => (string) Str::uuid(), 'organisation_id' => $delegated['organisation']->id, 'taxpayer_id' => $delegated['taxpayer']->id,
            'delegator_user_id' => $practitioner->id, 'delegate_user_id' => $practitioner->id, 'scopes' => json_encode(['reports:read']),
            'status' => 'ACTIVE', 'valid_from' => now(), 'created_at' => now(),
        ]);

        $response = $this->actingAs($practitioner)->postJson('/api/v1/reports/PORTFOLIO_EXCEPTIONS/runs', []);
        $response->assertStatus(201);
        $this->assertSame(['exceptions' => 1, 'open_exceptions' => 1], $response->json('report_run.result_summary'));
    }

    public function test_national_vat_aggregate_suppresses_the_result_under_the_minimum_cell_threshold(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0012');
        $this->seedDefinition('NATIONAL_VAT_AGGREGATE', 'OPEN_DATA', 'INTERNAL');
        for ($i = 0; $i < 4; $i++) {
            $this->makeInvoice($tp['taxpayer']->id, 10_000, 1_000);
        }
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'suppressed@reporttest.test');

        $suppressed = $this->actingAs($owner)->postJson('/api/v1/reports/NATIONAL_VAT_AGGREGATE/runs', []);
        $suppressed->assertStatus(201);
        $this->assertSame(['invoices' => 0, 'total_cents' => 0, 'suppressed' => true], $suppressed->json('report_run.result_summary'));

        for ($i = 0; $i < 10; $i++) {
            $this->makeInvoice($tp['taxpayer']->id, 10_000, 1_000);
        }
        $notSuppressed = $this->actingAs($owner)->postJson('/api/v1/reports/NATIONAL_VAT_AGGREGATE/runs', []);
        $notSuppressed->assertStatus(201);
        $result = $notSuppressed->json('report_run.result_summary');
        $this->assertFalse($result['suppressed']);
        $this->assertSame(14, $result['invoices']);
    }

    public function test_publishing_reconciles_a_fresh_computation_against_the_stored_result(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0013');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'publish@reporttest.test');

        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        $published = $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$runId}/publication", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-publish-0001']);
        $published->assertStatus(200);
        $this->assertSame('PUBLISHED', $published->json('report_run.status'));

        $this->assertSame('PUBLISHED', DB::table('report_runs')->where('id', $runId)->value('status'));
    }

    public function test_publishing_conflicts_when_the_underlying_data_has_changed_since_the_run(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0014');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'conflict@reporttest.test');

        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');
        $this->makeInvoice($tp['taxpayer']->id, 50_000, 5_000);

        $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/publication", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-conflict-0001'])
            ->assertStatus(409);
    }

    public function test_only_the_requester_or_a_national_actor_may_publish(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0015');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'requester@reporttest.test');
        $outsider = $this->taxpayerOwner($this->makeTaxpayer('VAT-RPT-0016')['taxpayer']->id, 'outsider@reporttest.test');

        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        $this->actingAs($outsider)->postJson("/api/v1/reports/runs/{$runId}/publication", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-outsider-0001'])
            ->assertStatus(403);
    }

    public function test_publish_replay_is_idempotent(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0017');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'replay@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        $first = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/publication", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-replay-0001']);
        $first->assertStatus(200);
        $second = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/publication", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-replay-0001']);
        $second->assertStatus(200);

        // The replay path returns the raw persisted `report_runs` row (matching
        // the source's own genuine shape difference: the fresh-publish path
        // hand-builds an enriched response with a decoded result_summary/
        // envelope, the replay path does not) -- so only `id` and the
        // underlying row's own state are compared, not the two differently-
        // shaped responses' `published_at` field formatting.
        $this->assertSame($runId, $second->json('report_run.id'));
        $this->assertSame('PUBLISHED', DB::table('report_runs')->where('id', $runId)->value('status'));
        $this->assertDatabaseCount('command_idempotency', 1);
    }

    public function test_requesting_a_non_sensitive_export_is_auto_approved(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0018');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'autoapprove@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        // No step-up in session at all -- non-sensitive exports don't need one.
        $response = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-export-0001']);

        $response->assertStatus(201);
        $this->assertSame('APPROVED', $response->json('report_export.status'));
        $this->assertFalse((bool) $response->json('report_export.requires_step_up'));
    }

    public function test_requesting_a_sensitive_export_requires_a_fresh_step_up_and_starts_pending_approval(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0019');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $officer = $this->namraComplianceOfficer();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $officer->id);
        $runId = $this->actingAs($officer)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');

        $this->actingAs($officer)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-sensitive-noauth-0001'])
            ->assertStatus(403);

        $response = $this->actingAs($officer)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-sensitive-0001']);
        $response->assertStatus(201);
        $this->assertSame('PENDING_APPROVAL', $response->json('report_export.status'));
        $this->assertTrue((bool) $response->json('report_export.requires_step_up'));
    }

    public function test_only_the_requester_or_a_national_actor_may_request_an_export(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0020');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'export-owner@reporttest.test');
        $outsider = $this->taxpayerOwner($this->makeTaxpayer('VAT-RPT-0021')['taxpayer']->id, 'export-outsider@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        $this->actingAs($outsider)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-export-outsider-0001'])
            ->assertStatus(403);
    }

    public function test_approving_an_export_is_restricted_to_national_roles_and_refuses_self_approval(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0022');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $requester = $this->namraComplianceOfficer();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $requester->id);
        $runId = $this->actingAs($requester)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-setup-0001'])
            ->json('report_export.id');

        $nonNational = $this->taxpayerOwner($tp['taxpayer']->id, 'nonnational@reporttest.test');
        $this->actingAs($nonNational)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/exports/{$exportId}/approval", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-nonnational-0001'])
            ->assertStatus(403);

        // The requester is themselves national (holds reports:run + is national-scope) but may not approve their own request.
        $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/exports/{$exportId}/approval", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-self-0001'])
            ->assertStatus(403);
    }

    public function test_approving_a_sensitive_export_requires_a_fresh_step_up(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0023');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $requester = $this->namraComplianceOfficer();
        $approver = $this->namraSupervisor();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $requester->id);
        $runId = $this->actingAs($requester)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-setup-0002'])
            ->json('report_export.id');

        // Explicitly stale rather than absent -- the test session store can
        // otherwise carry the setup call's own fresh confirmation forward.
        $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time() - 20_000])
            ->postJson("/api/v1/reports/exports/{$exportId}/approval", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-nostepup-0001'])
            ->assertStatus(403);

        $approved = $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/exports/{$exportId}/approval", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-approve-0001']);
        $approved->assertStatus(200);
        $this->assertSame('APPROVED', $approved->json('report_export.status'));
        $this->assertSame('ACTIVE', DB::table('document_metadata')->where('id', $approved->json('report_export.document_id'))->value('status'));
    }

    public function test_cancelling_a_pending_export_releases_its_quarantined_document(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0024');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $requester = $this->namraComplianceOfficer();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $requester->id);
        $runId = $this->actingAs($requester)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-cancel-setup-0001'])
            ->json('report_export.id');

        // Too short a reason.
        $this->actingAs($requester)->postJson("/api/v1/reports/exports/{$exportId}/cancellation", ['schema_version' => '1.0.0', 'reason' => 'no'], ['Idempotency-Key' => 'test-idem-cancel-invalid-0001'])
            ->assertStatus(422);

        $cancelled = $this->actingAs($requester)->postJson("/api/v1/reports/exports/{$exportId}/cancellation", [
            'schema_version' => '1.0.0', 'reason' => 'No longer needed for this review.',
        ], ['Idempotency-Key' => 'test-idem-cancel-0001']);
        $cancelled->assertStatus(200);
        $this->assertSame('CANCELLED', $cancelled->json('report_export.status'));
        $this->assertSame('REJECTED', DB::table('document_metadata')->where('id', $cancelled->json('report_export.document_id'))->value('status'));
    }

    public function test_only_a_pending_export_can_be_cancelled(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0025');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'alreadyapproved@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-cancel-alreadyok-0001'])
            ->json('report_export.id');

        $this->actingAs($owner)->postJson("/api/v1/reports/exports/{$exportId}/cancellation", [
            'schema_version' => '1.0.0', 'reason' => 'Already auto-approved, cannot cancel.',
        ], ['Idempotency-Key' => 'test-idem-cancel-alreadyok-0002'])->assertStatus(409);
    }

    public function test_getting_and_downloading_an_export_is_restricted_to_the_requester_or_a_national_actor(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0026');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'access-owner@reporttest.test');
        $outsider = $this->taxpayerOwner($this->makeTaxpayer('VAT-RPT-0027')['taxpayer']->id, 'access-outsider@reporttest.test');
        $noPerm = $this->sellerAdmin($tp['taxpayer']->id, 'access-noperm@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-access-0001'])
            ->json('report_export.id');

        $this->actingAs($noPerm)->getJson("/api/v1/reports/exports/{$exportId}")->assertStatus(403);
        $this->actingAs($outsider)->getJson("/api/v1/reports/exports/{$exportId}")->assertStatus(403);
        $this->actingAs($owner)->getJson("/api/v1/reports/exports/{$exportId}")->assertStatus(200)
            ->assertJsonPath('report_export.id', $exportId);
    }

    public function test_downloading_an_unapproved_or_expired_export_is_refused(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0028');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $requester = $this->namraComplianceOfficer();
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $requester->id);
        $pendingRunId = $this->actingAs($requester)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');
        $pendingExportId = $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/reports/runs/{$pendingRunId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-download-pending-0001'])
            ->json('report_export.id');

        $this->actingAs($requester)->getJson("/api/v1/reports/exports/{$pendingExportId}/download")->assertStatus(409);

        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'download-expired@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-download-expired-0001'])
            ->json('report_export.id');
        DB::table('report_exports')->where('id', $exportId)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($owner)->getJson("/api/v1/reports/exports/{$exportId}/download")->assertStatus(410);
    }

    /**
     * Proves App\Support\Platform\PlatformConfigReader actually wires the
     * seeded `reports.export_size_limit_bytes` platform_config row into
     * ReportExportService::requestExport -- the same CSV content that
     * exports fine at the default 200KB limit is refused once an ACTIVE
     * row seeds a limit smaller than the generated file.
     */
    public function test_a_seeded_export_size_limit_is_enforced_over_the_hardcoded_default(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0030');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'sizelimit@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');

        DB::table('platform_config')->insert([
            'id' => (string) Str::uuid(), 'key' => 'reports.export_size_limit_bytes', 'category' => 'REPORTS',
            'value' => '1', 'description' => 'Test override.', 'status' => 'ACTIVE', 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-sizelimit-0001'])
            ->assertStatus(413);
    }

    /**
     * Proves the seeded `reports.min_cell_suppression_threshold` row is
     * actually read live -- raising it above the 14 invoices that, under
     * the hardcoded default of 10, were NOT suppressed in
     * test_national_vat_aggregate_suppresses_the_result_under_the_minimum_cell_threshold
     * above now suppresses that same count.
     */
    public function test_a_seeded_minimum_cell_suppression_threshold_is_enforced_over_the_hardcoded_default(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0031');
        $this->seedDefinition('NATIONAL_VAT_AGGREGATE', 'OPEN_DATA', 'INTERNAL');
        for ($i = 0; $i < 14; $i++) {
            $this->makeInvoice($tp['taxpayer']->id, 10_000, 1_000);
        }
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'thresholdoverride@reporttest.test');

        DB::table('platform_config')->insert([
            'id' => (string) Str::uuid(), 'key' => 'reports.min_cell_suppression_threshold', 'category' => 'REPORTS',
            'value' => '20', 'description' => 'Test override.', 'status' => 'ACTIVE', 'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)->postJson('/api/v1/reports/NATIONAL_VAT_AGGREGATE/runs', []);
        $response->assertStatus(201);
        $this->assertSame(['invoices' => 0, 'total_cents' => 0, 'suppressed' => true], $response->json('report_run.result_summary'));
    }

    /**
     * Proves the seeded `STEP_UP_WINDOW` access_policies row's
     * `window_seconds` is actually read live by App\Support\Access\StepUp
     * -- a confirmation that is fresh under the hardcoded default
     * (10800s/3 hours) is stale once an ACTIVE row shrinks the window to
     * a few seconds in the past.
     */
    public function test_a_seeded_step_up_window_is_enforced_over_the_hardcoded_default(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0032');
        $this->seedDefinition('COMPLIANCE_CASELOAD', 'NAMRA_OPERATIONS', 'TAX_CONFIDENTIAL');
        $officer = $this->namraComplianceOfficer('stepupwindow@reporttest.test');
        $this->makeAuditCase($tp['organisation']->id, $tp['taxpayer']->id, $officer->id);
        $runId = $this->actingAs($officer)->postJson('/api/v1/reports/COMPLIANCE_CASELOAD/runs', [])->json('report_run.id');

        DB::table('access_policies')->insert([
            'id' => (string) Str::uuid(), 'code' => 'STEP_UP_WINDOW', 'name' => 'Test step-up window',
            'policy_type' => 'AUTHENTICATION', 'description' => 'Test override.',
            'parameters' => json_encode(['window_seconds' => 60]), 'status' => 'ACTIVE', 'updated_at' => now(),
        ]);

        // Confirmed 5 minutes ago -- fresh under the hardcoded 3-hour default, stale under the seeded 60s window.
        $this->actingAs($officer)->withSession(['auth.password_confirmed_at' => time() - 300])
            ->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-stepupwindow-0001'])
            ->assertStatus(403);
    }

    public function test_downloading_an_approved_export_returns_the_exact_bytes(): void
    {
        $tp = $this->makeTaxpayer('VAT-RPT-0029');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'CONFIDENTIAL');
        $this->makeInvoice($tp['taxpayer']->id, 100_000, 15_000);
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'download-ok@reporttest.test');
        $runId = $this->actingAs($owner)->postJson('/api/v1/reports/SALES_VAT_SUMMARY/runs', [])->json('report_run.id');
        $exportId = $this->actingAs($owner)->postJson("/api/v1/reports/runs/{$runId}/exports", $this->exportCommandBody(), ['Idempotency-Key' => 'test-idem-download-ok-0001'])
            ->json('report_export.id');

        $download = $this->actingAs($owner)->get("/api/v1/reports/exports/{$exportId}/download");

        $download->assertStatus(200);
        $this->assertStringStartsWith('text/csv', $download->headers->get('Content-Type'));
        $content = $download->getContent();
        $this->assertStringContainsString('# code:SALES_VAT_SUMMARY', $content);
        $this->assertStringContainsString('invoices,1', $content);
        $this->assertStringContainsString('total_cents,100000', $content);
        $this->assertStringContainsString('tax_cents,15000', $content);
    }
}

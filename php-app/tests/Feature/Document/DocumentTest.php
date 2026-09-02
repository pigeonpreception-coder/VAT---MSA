<?php

namespace Tests\Feature\Document;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Document\DocumentService (ported from
 * lib/data/platform-repository.ts's document commands) -- Module 22's
 * Documents & Records slice, now closed out in full: the Upload ->
 * Quarantine -> ScanDecision chain pulled forward in Phase 11 as the real
 * prerequisite for `DOCUMENT`-sourced evidence citation, plus this Phase
 * 13 pass's own supersede/versionHistory/retentionHold/download. See
 * tests/Feature/Compliance/ComplianceCaseTest.php for the evidence-citation
 * side of the earlier gap (addEvidence/recordEvidenceCustodyEvent's
 * DOCUMENT branches).
 */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
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

    // documents:upload, not national scope -- exactly the role/scope shape uploadDocument expects a submitting party to have.
    private function taxpayerOwner(string $taxpayerId, string $email = 'owner@doctest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    // documents:manage AND national scope -- exactly what completeDocumentScan requires of the caller.
    private function systemAdmin(string $email = 'sysadmin@doctest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA System Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    // cases:manage AND national scope -- the compliance evidence-citation actor.
    private function namraAuditor(string $email = 'auditor@doctest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function fakeUpload(string $content, string $declaredMime, string $name = 'evidence.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $declaredMime, null, true);
    }

    private function minimalPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
    }

    public function test_uploading_a_valid_document_quarantines_it_with_correct_metadata(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0001');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $bytes = $this->minimalPdfBytes();

        $response = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'expense', 'owner_resource_id' => 'expense-0001', 'classification' => 'confidential',
            'file' => $this->fakeUpload($bytes, 'application/pdf'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('document.status', 'QUARANTINED')
            ->assertJsonPath('document.scan_status', 'PENDING_EXTERNAL_SCANNER')
            ->assertJsonPath('document.owner_domain', 'EXPENSE')
            ->assertJsonPath('document.owner_resource_id', 'expense-0001')
            ->assertJsonPath('document.classification', 'CONFIDENTIAL')
            ->assertJsonPath('document.checksum_sha256', hash('sha256', $bytes))
            ->assertJsonPath('document.organisation_id', $tp['organisation']->id);

        $documentId = $response->json('document.id');
        $this->assertDatabaseHas('document_metadata', [
            'id' => $documentId, 'organisation_id' => $tp['organisation']->id, 'status' => 'QUARANTINED',
            'checksum_sha256' => hash('sha256', $bytes), 'legal_hold' => false,
        ]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $documentId, 'event_type' => 'DocumentQuarantined']);
        $this->assertDatabaseHas('audit_events', ['action' => 'DOCUMENT_QUARANTINED', 'resource_id' => $documentId]);

        $objectKey = "quarantine/{$tp['organisation']->id}/{$documentId}/evidence.pdf";
        Storage::disk('local')->assertExists($objectKey);
    }

    public function test_uploading_content_that_does_not_match_its_declared_type_is_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0002');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        // Real PNG-signature bytes, falsely declared as a PDF.
        $pngBytes = "\x89PNG\r\n\x1a\n".str_repeat("\x00", 32);

        $response = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0002', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($pngBytes, 'application/pdf'),
        ]);

        $response->assertStatus(415);
        $this->assertDatabaseCount('document_metadata', 0);
    }

    public function test_uploading_a_disallowed_mime_type_is_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0003');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);

        $response = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0003', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload('plain text content', 'text/plain', 'notes.txt'),
        ]);

        $response->assertStatus(415);
    }

    public function test_uploading_an_oversized_or_empty_file_is_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0004');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);

        $empty = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0004a', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload('', 'application/pdf'),
        ]);
        $empty->assertStatus(413);

        $oversized = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0004b', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload("%PDF-1.4\n".str_repeat('A', 10_485_760), 'application/pdf'),
        ]);
        $oversized->assertStatus(413);
    }

    public function test_uploading_with_an_invalid_owner_domain_or_classification_is_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0005');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $bytes = $this->minimalPdfBytes();

        $badDomain = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'NOT_A_REAL_DOMAIN', 'owner_resource_id' => 'expense-0005', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($bytes, 'application/pdf'),
        ]);
        $badDomain->assertStatus(422);

        $badClassification = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0005', 'classification' => 'NOT_A_REAL_CLASSIFICATION',
            'file' => $this->fakeUpload($bytes, 'application/pdf'),
        ]);
        $badClassification->assertStatus(422);
    }

    public function test_only_an_authorised_national_role_can_record_a_scan_result(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0006');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $upload = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0006', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');

        // The uploading taxpayer has documents:upload but not documents:manage.
        $denied = $this->actingAs($owner)->postJson("/api/v1/documents/{$upload}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-scan-denied-0001']);
        $denied->assertStatus(403);
    }

    public function test_completing_a_scan_clean_activates_and_infected_permanently_rejects(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0007');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();

        $cleanId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0007a', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');
        $infectedId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0007b', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');

        $clean = $this->actingAs($admin)->postJson("/api/v1/documents/{$cleanId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'clean'], ['Idempotency-Key' => 'test-idem-scan-clean-0001']);
        $clean->assertStatus(200)->assertJsonPath('document.status', 'ACTIVE')->assertJsonPath('document.scan_status', 'CLEAN');
        $this->assertDatabaseHas('document_metadata', ['id' => $cleanId, 'status' => 'ACTIVE', 'scanned_by' => $admin->id]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $cleanId, 'event_type' => 'DocumentScanClean']);

        $infected = $this->actingAs($admin)->postJson("/api/v1/documents/{$infectedId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'INFECTED', 'notes' => 'Detected EICAR test signature.'], ['Idempotency-Key' => 'test-idem-scan-infected-0001']);
        $infected->assertStatus(200)->assertJsonPath('document.status', 'REJECTED')->assertJsonPath('document.scan_status', 'INFECTED');
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $infectedId, 'event_type' => 'DocumentScanInfected']);

        // Idempotent replay with the same key + payload returns the same resource rather than erroring.
        $replay = $this->actingAs($admin)->postJson("/api/v1/documents/{$cleanId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'clean'], ['Idempotency-Key' => 'test-idem-scan-clean-0001']);
        $replay->assertStatus(200)->assertJsonPath('document.id', $cleanId)->assertJsonPath('document.status', 'ACTIVE');

        // A second, genuinely new scan attempt against an already-decided document conflicts.
        $conflict = $this->actingAs($admin)->postJson("/api/v1/documents/{$cleanId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'INFECTED'], ['Idempotency-Key' => 'test-idem-scan-conflict-0001']);
        $conflict->assertStatus(409);
    }

    public function test_citing_a_document_as_evidence_is_refused_until_it_is_clean_scanned_then_succeeds(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0008');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $auditor = $this->namraAuditor();
        $bytes = $this->minimalPdfBytes();

        $documentId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'AUDIT_CASE', 'owner_resource_id' => 'audit-0008', 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload($bytes, 'application/pdf'),
        ])->json('document.id');

        $caseResponse = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-doc-case-0008']);
        $caseId = $caseResponse->json('resource.id');

        // Still PENDING_EXTERNAL_SCANNER: citing it as evidence is a conflict, not a silent success.
        $tooSoon = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'DOCUMENT', 'source_resource_id' => $documentId,
            'description' => 'Supporting document, not yet scanned.',
        ], ['Idempotency-Key' => 'test-idem-doc-evidence-early-0008']);
        $tooSoon->assertStatus(409);

        $this->actingAs($admin)->postJson("/api/v1/documents/{$documentId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-doc-scan-0008'])->assertStatus(200);

        $add = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'DOCUMENT', 'source_resource_id' => $documentId,
            'description' => 'Supporting document, clean-scanned.',
        ], ['Idempotency-Key' => 'test-idem-doc-evidence-0008']);
        $add->assertStatus(201)
            ->assertJsonPath('resource.status', 'PRESERVED')
            ->assertJsonPath('resource.evidence_type', 'UPLOADED_DOCUMENT')
            ->assertJsonPath('resource.document_id', $documentId)
            ->assertJsonPath('resource.checksum_sha256', hash('sha256', $bytes));
        $evidenceId = $add->json('resource.id');

        // VERIFY re-derives the document's current checksum and confirms the match.
        $verify = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events",
            ['schema_version' => '1.0.0', 'action' => 'VERIFY'], ['Idempotency-Key' => 'test-idem-doc-verify-0008']);
        $verify->assertStatus(200);
        $this->assertDatabaseHas('audit_evidence_custody_events', ['audit_evidence_id' => $evidenceId, 'action' => 'VERIFY', 'integrity_verified' => 1]);

        // SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD cascade onto the underlying document_metadata row.
        $hold = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events",
            ['schema_version' => '1.0.0', 'action' => 'SET_LEGAL_HOLD', 'notes' => 'Preserving pending litigation.'], ['Idempotency-Key' => 'test-idem-doc-hold-0008']);
        $hold->assertStatus(200)->assertJsonPath('resource.legal_hold', true);
        $this->assertDatabaseHas('document_metadata', ['id' => $documentId, 'legal_hold' => true]);

        $release = $this->actingAs($auditor)->postJson("/api/v1/audit-evidence/{$evidenceId}/custody-events",
            ['schema_version' => '1.0.0', 'action' => 'RELEASE_LEGAL_HOLD', 'notes' => 'Litigation concluded, hold no longer required.'], ['Idempotency-Key' => 'test-idem-doc-release-0008']);
        $release->assertStatus(200)->assertJsonPath('resource.legal_hold', false);
        $this->assertDatabaseHas('document_metadata', ['id' => $documentId, 'legal_hold' => false]);
    }

    /** Uploads a document and drives it straight to ACTIVE via a CLEAN scan -- the shape most of the new commands below need as their starting point. */
    private function activeDocument(User $owner, User $admin, string $ownerResourceId): string
    {
        $id = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => $ownerResourceId, 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');
        $this->actingAs($admin)->postJson("/api/v1/documents/{$id}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-active-'.Str::random(20)])->assertStatus(200);

        return $id;
    }

    public function test_superseding_an_active_document_quarantines_the_replacement_and_flips_the_original(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0009');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $originalId = $this->activeDocument($owner, $admin, 'expense-0009');
        $newBytes = "%PDF-1.4\n%corrected\n%%EOF";

        $supersede = $this->actingAs($owner)->post("/api/v1/documents/{$originalId}/supersession", [
            'file' => $this->fakeUpload($newBytes, 'application/pdf', 'corrected.pdf'),
        ]);

        $supersede->assertStatus(201)
            ->assertJsonPath('document.status', 'QUARANTINED')
            ->assertJsonPath('document.scan_status', 'PENDING_EXTERNAL_SCANNER')
            ->assertJsonPath('document.supersedes_document_id', $originalId)
            ->assertJsonPath('document.owner_domain', 'EXPENSE')
            ->assertJsonPath('document.owner_resource_id', 'expense-0009')
            ->assertJsonPath('document.checksum_sha256', hash('sha256', $newBytes));
        $newId = $supersede->json('document.id');

        $this->assertDatabaseHas('document_metadata', ['id' => $originalId, 'status' => 'SUPERSEDED']);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $newId, 'event_type' => 'DocumentSuperseded']);
        $this->assertDatabaseHas('audit_events', ['action' => 'DOCUMENT_SUPERSEDED', 'resource_id' => $newId]);
    }

    public function test_only_a_clean_active_document_can_be_superseded(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0010');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $quarantinedId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0010', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');

        $conflict = $this->actingAs($owner)->post("/api/v1/documents/{$quarantinedId}/supersession", [
            'file' => $this->fakeUpload("%PDF-1.4\n%replacement\n%%EOF", 'application/pdf'),
        ]);

        $conflict->assertStatus(409);
        $this->assertDatabaseHas('document_metadata', ['id' => $quarantinedId, 'status' => 'QUARANTINED']);
    }

    public function test_version_history_walks_the_full_supersession_chain_oldest_first(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0011');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $v1 = $this->activeDocument($owner, $admin, 'expense-0011');

        $v2 = $this->actingAs($owner)->post("/api/v1/documents/{$v1}/supersession", [
            'file' => $this->fakeUpload("%PDF-1.4\n%v2\n%%EOF", 'application/pdf'),
        ])->json('document.id');
        $this->actingAs($admin)->postJson("/api/v1/documents/{$v2}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-v2-clean-0011'])->assertStatus(200);

        $v3 = $this->actingAs($owner)->post("/api/v1/documents/{$v2}/supersession", [
            'file' => $this->fakeUpload("%PDF-1.4\n%v3\n%%EOF", 'application/pdf'),
        ])->json('document.id');

        // Querying from ANY version in the chain (not just the newest) returns the complete history.
        $history = $this->actingAs($owner)->getJson("/api/v1/documents/{$v1}/versions");
        $history->assertStatus(200)->assertJsonPath('document_id', $v1);
        $ids = collect($history->json('versions'))->pluck('id')->all();
        $this->assertSame([$v1, $v2, $v3], $ids);
        $this->assertSame(['SUPERSEDED', 'SUPERSEDED', 'QUARANTINED'], collect($history->json('versions'))->pluck('status')->all());
    }

    public function test_a_taxpayer_outside_the_documents_organisation_cannot_view_its_version_history(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0012');
        $stranger = $this->makeTaxpayer('VAT-DOC-0013');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $strangerOwner = $this->taxpayerOwner($stranger['taxpayer']->id, 'stranger@doctest.test');
        $admin = $this->systemAdmin();
        $documentId = $this->activeDocument($owner, $admin, 'expense-0012');

        $this->actingAs($strangerOwner)->getJson("/api/v1/documents/{$documentId}/versions")->assertStatus(403);
    }

    public function test_setting_a_retention_hold_requires_national_scope_and_cascades_to_cited_evidence(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0014');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $auditor = $this->namraAuditor();
        $documentId = $this->activeDocument($owner, $admin, 'expense-0014');

        // The uploading taxpayer holds documents:upload but not documents:manage.
        $denied = $this->actingAs($owner)->postJson("/api/v1/documents/{$documentId}/retention-hold",
            ['schema_version' => '1.0.0', 'action' => 'APPLY', 'notes' => 'Attempting a hold without permission.'],
            ['Idempotency-Key' => 'test-idem-hold-denied-0014']);
        $denied->assertStatus(403);

        $caseId = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Retention-hold cascade check', 'opening_reason' => 'Verifying the direct hold path stays in sync with evidence custody.', 'risk_tier' => 'MEDIUM',
        ], ['Idempotency-Key' => 'test-idem-hold-case-0014'])->json('resource.id');
        $evidenceId = $this->actingAs($auditor)->postJson("/api/v1/audit-cases/{$caseId}/evidence", [
            'schema_version' => '1.0.0', 'source_resource_type' => 'DOCUMENT', 'source_resource_id' => $documentId, 'description' => 'Cited for the hold-cascade test.',
        ], ['Idempotency-Key' => 'test-idem-hold-evidence-0014'])->json('resource.id');

        $apply = $this->actingAs($admin)->postJson("/api/v1/documents/{$documentId}/retention-hold",
            ['schema_version' => '1.0.0', 'action' => 'APPLY', 'notes' => 'Preserving pending a compliance review.', 'retained_until' => '2027-06-30'],
            ['Idempotency-Key' => 'test-idem-hold-apply-0014']);
        $apply->assertStatus(200)->assertJsonPath('document.legal_hold', true);
        $this->assertDatabaseHas('document_metadata', ['id' => $documentId, 'legal_hold' => true]);
        $this->assertDatabaseHas('audit_evidence', ['id' => $evidenceId, 'legal_hold' => true]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $documentId, 'event_type' => 'DocumentRetentionHoldApplied']);

        $release = $this->actingAs($admin)->postJson("/api/v1/documents/{$documentId}/retention-hold",
            ['schema_version' => '1.0.0', 'action' => 'RELEASE', 'notes' => 'Compliance review concluded.'],
            ['Idempotency-Key' => 'test-idem-hold-release-0014']);
        $release->assertStatus(200)->assertJsonPath('document.legal_hold', false);
        $this->assertDatabaseHas('document_metadata', ['id' => $documentId, 'legal_hold' => false]);
        $this->assertDatabaseHas('audit_evidence', ['id' => $evidenceId, 'legal_hold' => false]);
    }

    public function test_download_is_refused_before_a_clean_scan_and_succeeds_after_with_the_original_bytes(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0015');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $bytes = $this->minimalPdfBytes();

        $quarantinedId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0015', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($bytes, 'application/pdf', 'evidence.pdf'),
        ])->json('document.id');

        $tooSoon = $this->actingAs($owner)->get("/api/v1/documents/{$quarantinedId}/download");
        $tooSoon->assertStatus(409);

        $this->actingAs($admin)->postJson("/api/v1/documents/{$quarantinedId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-download-clean-0015'])->assertStatus(200);

        $download = $this->actingAs($owner)->get("/api/v1/documents/{$quarantinedId}/download");
        $download->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="evidence.pdf"');
        $this->assertSame($bytes, $download->getContent());
        $this->assertDatabaseHas('audit_events', ['action' => 'DOCUMENT_DOWNLOADED', 'resource_id' => $quarantinedId]);
    }

    public function test_download_is_permanently_refused_for_an_infected_document(): void
    {
        $tp = $this->makeTaxpayer('VAT-DOC-0016');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $admin = $this->systemAdmin();
        $infectedId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-0016', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');
        $this->actingAs($admin)->postJson("/api/v1/documents/{$infectedId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'INFECTED'], ['Idempotency-Key' => 'test-idem-download-infected-0016'])->assertStatus(200);

        $this->actingAs($owner)->get("/api/v1/documents/{$infectedId}/download")->assertStatus(409);
    }
}

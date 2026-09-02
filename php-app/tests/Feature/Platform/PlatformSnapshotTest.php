<?php

namespace Tests\Feature\Platform;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Platform\PlatformSnapshotService (ported from
 * lib/data/platform-repository.ts's getPlatformSnapshot/
 * getTechnicalPlatformSnapshot/getDocumentCustodySummary/
 * getDeveloperPortalSnapshot) -- Module 22's platform/developer-portal
 * snapshot reads, the next slice of Phase 13 after "Document module".
 */
class PlatformSnapshotTest extends TestCase
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

    private function pilotAdmin(string $email = 'pilot@platformtest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function superAdmin(string $email = 'super@platformtest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
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

    public function test_the_national_platform_snapshot_aggregates_every_domain(): void
    {
        $tp = $this->makeTaxpayer('VAT-PLAT-0001');
        $admin = $this->pilotAdmin();
        $now = now();

        $integrationId = (string) Str::uuid();
        DB::table('integration_connections')->insert([
            'id' => $integrationId, 'organisation_id' => $tp['organisation']->id, 'provider_key' => 'test-bank', 'category' => 'BANKING',
            'display_name' => 'Test Bank Feed', 'capabilities' => json_encode(['STATEMENT_IMPORT']), 'configuration_status' => 'CONFIGURED',
            'operational_status' => 'HEALTHY', 'data_classification' => 'CONFIDENTIAL', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $apiClientId = (string) Str::uuid();
        DB::table('api_clients')->insert([
            'id' => $apiClientId, 'organisation_id' => $tp['organisation']->id, 'name' => 'Test Integration', 'client_key' => 'ck_'.Str::random(16),
            'scopes' => json_encode(['invoices:read']), 'credential_reference' => 'cred-ref-0001', 'status' => 'ACTIVE',
            'rate_limit_profile' => 'STANDARD', 'created_by' => $admin->id, 'created_at' => $now,
        ]);
        DB::table('webhook_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'api_client_id' => $apiClientId, 'event_types' => json_encode(['InvoiceCertified']),
            'endpoint_url' => 'https://example.test/webhook', 'signing_key_reference' => 'sign-ref-0001', 'status' => 'ACTIVE', 'created_at' => $now,
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) Str::uuid(), 'integration_connection_id' => $integrationId, 'organisation_id' => $tp['organisation']->id,
            'job_type' => 'BANK_STATEMENT', 'direction' => 'INBOUND', 'status' => 'COMPLETED', 'requested_by' => $admin->id, 'requested_at' => $now,
        ]);
        DB::table('bank_imports')->insert([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'integration_connection_id' => $integrationId,
            'bank_name' => 'Test Bank', 'account_reference_masked' => '****1234', 'statement_from' => '2026-08-01', 'statement_to' => '2026-08-31',
            'currency' => 'NAD', 'status' => 'PROCESSED', 'requested_by' => $admin->id, 'created_at' => $now,
        ]);
        DB::table('payment_instructions')->insert([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $tp['taxpayer']->id, 'amount_cents' => 500000, 'currency' => 'NAD',
            'beneficiary_reference_masked' => '****5678', 'provider' => 'TEST_RAIL', 'status' => 'SETTLED', 'idempotency_key' => Str::random(20),
            'approved_by' => $admin->id, 'approved_at' => $now,
        ]);
        $deviceId = (string) Str::uuid();
        DB::table('offline_devices')->insert([
            'id' => $deviceId, 'organisation_id' => $tp['organisation']->id, 'device_code' => 'DEV-0001', 'display_name' => 'Till 1',
            'status' => 'ACTIVE', 'enrolment_status' => 'ENROLLED', 'created_at' => $now,
        ]);
        DB::table('offline_number_ranges')->insert([
            'id' => (string) Str::uuid(), 'offline_device_id' => $deviceId, 'document_type' => 'TAX_INVOICE', 'prefix' => 'OFF',
            'range_start' => 1, 'range_end' => 1000, 'next_number' => 1, 'status' => 'ACTIVE', 'valid_from' => $now, 'valid_to' => $now->copy()->addYear(),
        ]);
        $batchId = (string) Str::uuid();
        DB::table('offline_sync_batches')->insert([
            'id' => $batchId, 'offline_device_id' => $deviceId, 'client_batch_id' => 'batch-0001', 'sequence_from' => 1, 'sequence_to' => 1,
            'batch_hash' => hash('sha256', 'batch-0001'), 'signature' => 'sig-0001', 'document_count' => 1, 'status' => 'PROCESSED', 'received_at' => $now,
        ]);
        DB::table('offline_conflicts')->insert([
            'id' => (string) Str::uuid(), 'offline_sync_batch_id' => $batchId, 'conflict_type' => 'DUPLICATE_NUMBER',
            'source_document_id' => 'src-doc-0001', 'status' => 'OPEN', 'created_at' => $now,
        ]);
        $reportDefinitionId = (string) Str::uuid();
        DB::table('report_definitions')->insert([
            'id' => $reportDefinitionId, 'code' => 'TEST-REPORT', 'name' => 'Test Report', 'audience' => 'INTERNAL',
            'description' => 'A report used purely to exercise the platform snapshot.', 'classification' => 'INTERNAL',
            'query_version' => '1', 'status' => 'ACTIVE', 'created_at' => $now,
        ]);
        DB::table('report_runs')->insert([
            'id' => (string) Str::uuid(), 'report_definition_id' => $reportDefinitionId, 'organisation_id' => $tp['organisation']->id,
            'parameters' => json_encode([]), 'status' => 'COMPLETED', 'requested_by' => $admin->id, 'requested_at' => $now,
        ]);
        DB::table('service_components')->insert([
            'id' => (string) Str::uuid(), 'component_key' => 'test-component', 'display_name' => 'Test Component', 'component_type' => 'SERVICE',
            'criticality' => 'HIGH', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'HEALTHY',
            'dependency_summary' => 'None.', 'status_detail' => 'Operating normally.',
        ]);
        $documentId = $this->actingAs($this->taxpayerOwner($tp['taxpayer']->id, 'doc-owner@platformtest.test'))->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'expense-plat-0001', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');

        $response = $this->actingAs($admin)->getJson('/api/v1/platform');
        $response->assertStatus(200);
        $body = $response->json();

        foreach (['integrations', 'clients', 'webhooks', 'syncJobs', 'bankImports', 'payments', 'devices', 'numberRanges', 'batches', 'conflicts', 'reportDefinitions', 'reportRuns', 'components', 'documents', 'outbox'] as $key) {
            $this->assertArrayHasKey($key, $body, "snapshot is missing the '{$key}' key");
        }

        $this->assertTrue(collect($body['integrations'])->contains('id', $integrationId));
        $this->assertTrue(collect($body['clients'])->contains(fn ($c) => $c['id'] === $apiClientId && $c['legal_name'] === $tp['organisation']->legal_name));
        $this->assertTrue(collect($body['webhooks'])->contains('endpoint_url', 'https://example.test/webhook'));
        $this->assertTrue(collect($body['syncJobs'])->contains('integration_connection_id', $integrationId));
        $this->assertTrue(collect($body['bankImports'])->contains('bank_name', 'Test Bank'));
        $this->assertTrue(collect($body['payments'])->contains('beneficiary_reference_masked', '****5678'));
        $this->assertTrue(collect($body['devices'])->contains('id', $deviceId));
        $this->assertTrue(collect($body['numberRanges'])->contains('offline_device_id', $deviceId));
        $this->assertTrue(collect($body['batches'])->contains('id', $batchId));
        $this->assertTrue(collect($body['conflicts'])->contains('offline_sync_batch_id', $batchId));
        $this->assertTrue(collect($body['reportDefinitions'])->contains('code', 'TEST-REPORT'));
        $this->assertTrue(collect($body['reportRuns'])->contains('report_definition_id', $reportDefinitionId));
        $this->assertTrue(collect($body['components'])->contains('component_key', 'test-component'));
        $this->assertTrue(collect($body['documents'])->contains('id', $documentId));
        $this->assertTrue(collect($body['outbox'])->pluck('status')->contains('PENDING'));
    }

    public function test_technical_only_roles_get_the_technical_snapshot_never_seeing_organisation_scoped_data(): void
    {
        $admin = $this->superAdmin();
        DB::table('service_components')->insert([
            'id' => (string) Str::uuid(), 'component_key' => 'tech-component', 'display_name' => 'Tech Component', 'component_type' => 'SERVICE',
            'criticality' => 'CRITICAL', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'HEALTHY',
            'dependency_summary' => 'None.', 'status_detail' => 'Operating normally.',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/platform');

        $response->assertStatus(200);
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['apiClients', 'components', 'integrations', 'outbox', 'securityEvents', 'syncJobs', 'webhooks'], $keys);
        $this->assertTrue(collect($response->json('components'))->contains('component_key', 'tech-component'));
    }

    public function test_platform_read_permission_is_required(): void
    {
        $tp = $this->makeTaxpayer('VAT-PLAT-0002');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'noaccess@platformtest.test');

        $this->actingAs($owner)->getJson('/api/v1/platform')->assertStatus(403);
    }

    public function test_document_custody_summary_counts_by_status_for_the_actors_own_organisation(): void
    {
        $tp = $this->makeTaxpayer('VAT-PLAT-0003');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'custody-owner@platformtest.test');
        $admin = $this->pilotAdmin();

        $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'custody-0001', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ]);
        $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'custody-0002', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ]);
        $cleanId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'custody-0003', 'classification' => 'INTERNAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->json('document.id');
        $this->actingAs($admin)->postJson("/api/v1/documents/{$cleanId}/scan-result",
            ['schema_version' => '1.0.0', 'outcome' => 'CLEAN'], ['Idempotency-Key' => 'test-idem-custody-clean-0001'])->assertStatus(200);

        $summary = $this->actingAs($owner)->getJson('/api/v1/platform/document-custody');

        $summary->assertStatus(200)->assertExactJson(['total' => 3, 'quarantined' => 2, 'clean' => 1]);
    }

    public function test_developer_portal_snapshot_requires_an_organisation_link_and_returns_real_data_once_linked(): void
    {
        $unlinked = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Unlinked Developer', 'email' => 'unlinked@platformtest.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        $notYetLinked = $this->actingAs($unlinked)->getJson('/api/v1/platform/developer-portal');
        $notYetLinked->assertStatus(200)->assertExactJson(['clients' => [], 'webhooks' => [], 'provisioning' => 'ORGANISATION_LINK_REQUIRED']);

        $tp = $this->makeTaxpayer('VAT-PLAT-0004');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'dev-owner@platformtest.test');
        $apiClientId = (string) Str::uuid();
        DB::table('api_clients')->insert([
            'id' => $apiClientId, 'organisation_id' => $tp['organisation']->id, 'name' => 'My Integration', 'client_key' => 'ck_'.Str::random(16),
            'scopes' => json_encode(['invoices:read']), 'credential_reference' => 'cred-ref-0002', 'status' => 'ACTIVE',
            'rate_limit_profile' => 'STANDARD', 'created_by' => $owner->id, 'created_at' => now(),
        ]);
        DB::table('webhook_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'api_client_id' => $apiClientId, 'event_types' => json_encode(['InvoiceCertified']),
            'endpoint_url' => 'https://example.test/dev-webhook', 'signing_key_reference' => 'sign-ref-0002', 'status' => 'ACTIVE', 'created_at' => now(),
        ]);

        $linked = $this->actingAs($owner)->getJson('/api/v1/platform/developer-portal');
        $linked->assertStatus(200)->assertJsonPath('provisioning', 'ORGANISED_SCOPE');
        $this->assertTrue(collect($linked->json('clients'))->contains('id', $apiClientId));
        $this->assertTrue(collect($linked->json('webhooks'))->contains('endpoint_url', 'https://example.test/dev-webhook'));
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Platform\DataProductService (ported from
 * lib/data/platform-repository.ts's listDataProducts/runAnalyticsModel/
 * publishDataProduct/queryApprovedMetrics/listAnomalyCandidates) --
 * Module 7 Phase D, Phase 13's fifth slice.
 */
class DataProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function pilotAdmin(string $email = 'pilot@dptest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
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

    private function seedReportDefinition(string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('report_definitions')->insert([
            'id' => $id, 'code' => $code, 'name' => "{$code} definition", 'audience' => 'TAXPAYER',
            'description' => 'Test report definition.', 'classification' => 'CONFIDENTIAL', 'query_version' => '1.0.0',
            'status' => 'ACTIVE', 'created_at' => now(), 'freshness_tier' => 'DAILY', 'guardrail' => 'test guardrail',
        ]);

        return $id;
    }

    /** @param array<string, mixed> $resultSummary */
    private function seedReportRun(string $definitionId, string $status, array $resultSummary, string $requestedBy): string
    {
        $id = (string) Str::uuid();
        DB::table('report_runs')->insert([
            'id' => $id, 'report_definition_id' => $definitionId, 'organisation_id' => null, 'taxpayer_id' => null,
            'parameters' => json_encode([]), 'status' => $status, 'row_count' => count($resultSummary),
            'result_summary' => json_encode($resultSummary), 'output_document_id' => null, 'requested_by' => $requestedBy,
            'requested_at' => now(), 'completed_at' => now(), 'expires_at' => now()->addDay(), 'error_code' => null,
            'scope_snapshot' => null, 'published_by' => $requestedBy, 'published_at' => now(),
        ]);

        return $id;
    }

    private function seedDataProduct(string $sourceReportDefinitionId, string $code = 'TEST_PRODUCT', string $status = 'ACTIVE'): string
    {
        $id = (string) Str::uuid();
        DB::table('data_products')->insert([
            'id' => $id, 'code' => $code, 'name' => "{$code} name", 'description' => 'Test data product.',
            'source_report_definition_id' => $sourceReportDefinitionId, 'status' => $status, 'created_at' => now(),
        ]);

        return $id;
    }

    private function seedMetric(string $dataProductId, string $code, string $field, string $status = 'CERTIFIED', float $thresholdPct = 25.0): string
    {
        $id = (string) Str::uuid();
        DB::table('metrics')->insert([
            'id' => $id, 'code' => $code, 'name' => "{$code} name", 'data_product_id' => $dataProductId,
            'field' => $field, 'unit' => 'COUNT', 'status' => $status, 'anomaly_threshold_pct' => $thresholdPct, 'created_at' => now(),
        ]);

        return $id;
    }

    /** @return array{schema_version: string} */
    private function schemaBody(): array
    {
        return ['schema_version' => '1.0.0'];
    }

    public function test_listing_data_products_requires_the_reports_read_permission(): void
    {
        $tp = $this->makeTaxpayer('VAT-DP-0001');
        $denied = $this->sellerAdmin($tp['taxpayer']->id, 'noperm@dptest.test');

        $this->actingAs($denied)->getJson('/api/v1/analytics/data-products')->assertStatus(403);
    }

    public function test_listing_data_products_returns_lineage_certified_metrics_and_the_latest_snapshot(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId, 'VAT_TRENDS');
        DB::table('data_product_lineage')->insert([
            'id' => (string) Str::uuid(), 'data_product_id' => $productId, 'source_type' => 'REPORT_DEFINITION',
            'source_id' => $definitionId, 'source_label' => 'SALES_VAT_SUMMARY', 'recorded_at' => now(),
        ]);
        $this->seedMetric($productId, 'INVOICE_COUNT', 'invoices', 'CERTIFIED');
        $this->seedMetric($productId, 'DRAFT_METRIC', 'total_cents', 'DRAFT');
        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 5, 'total_cents' => 100_000], $admin->id);
        $modelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $runId]), ['Idempotency-Key' => 'test-idem-dp-list-run-0001'])
            ->json('model_run.id');
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-list-pub-0001'])
            ->assertStatus(201);

        // Also seed an inactive product that must not appear in the list.
        $inactiveDefinitionId = $this->seedReportDefinition('COMPLIANCE_CASELOAD');
        $this->seedDataProduct($inactiveDefinitionId, 'INACTIVE_PRODUCT', 'RETIRED');

        $response = $this->actingAs($admin)->getJson('/api/v1/analytics/data-products');
        $response->assertStatus(200);
        $products = $response->json('data_products');
        $this->assertCount(1, $products);
        $product = $products[0];
        $this->assertSame('VAT_TRENDS', $product['code']);
        $this->assertSame('SALES_VAT_SUMMARY', $product['source']['report_code']);
        $this->assertTrue(collect($product['lineage'])->contains('source_label', 'SALES_VAT_SUMMARY'));
        $this->assertCount(1, $product['certified_metrics']);
        $this->assertSame('INVOICE_COUNT', $product['certified_metrics'][0]['code']);
        $this->assertNotNull($product['latest_snapshot']);
        $this->assertSame(['invoices' => 5, 'total_cents' => 100_000], $product['latest_snapshot']['snapshot']);
    }

    public function test_running_a_model_requires_national_scope(): void
    {
        $tp = $this->makeTaxpayer('VAT-DP-0002');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@dptest.test');
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);

        $this->actingAs($owner)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", $this->schemaBody(), ['Idempotency-Key' => 'test-idem-dp-scope-0001'])
            ->assertStatus(403);
    }

    public function test_running_a_model_requires_an_active_data_product_and_a_report_run_that_matches_its_source_definition(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $otherDefinitionId = $this->seedReportDefinition('NATIONAL_VAT_AGGREGATE');
        $productId = $this->seedDataProduct($definitionId);

        // Unknown data product -- a syntactically valid report_run_id is
        // supplied so the request clears validation and actually reaches
        // the data-product lookup (which runs before the report-run
        // lookup), matching the service's own check order.
        $this->actingAs($admin)->postJson('/api/v1/analytics/data-products/'.((string) Str::uuid()).'/model-runs', array_merge($this->schemaBody(), ['report_run_id' => (string) Str::uuid()]), ['Idempotency-Key' => 'test-idem-dp-unknownproduct-0001'])
            ->assertStatus(404);

        // Unknown report run.
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", $this->schemaBody(), ['Idempotency-Key' => 'test-idem-dp-unknownrun-0001'])
            ->assertStatus(422); // report_run_id missing entirely fails validation first.

        $mismatchedRunId = $this->seedReportRun($otherDefinitionId, 'PUBLISHED', ['invoices' => 1], $admin->id);
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $mismatchedRunId]), ['Idempotency-Key' => 'test-idem-dp-mismatch-0001'])
            ->assertStatus(422);

        $unpublishedRunId = $this->seedReportRun($definitionId, 'COMPLETED_INLINE', ['invoices' => 1], $admin->id);
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $unpublishedRunId]), ['Idempotency-Key' => 'test-idem-dp-unpublished-0001'])
            ->assertStatus(409);

        $suppressedRunId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 0, 'total_cents' => 0, 'suppressed' => true], $admin->id);
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $suppressedRunId]), ['Idempotency-Key' => 'test-idem-dp-suppressed-0001'])
            ->assertStatus(409);
    }

    public function test_running_a_model_succeeds_and_is_idempotent(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);
        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 5, 'total_cents' => 100_000], $admin->id);
        $body = array_merge($this->schemaBody(), ['report_run_id' => $runId]);

        $first = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", $body, ['Idempotency-Key' => 'test-idem-dp-run-0001']);
        $first->assertStatus(201);
        $this->assertSame('COMPLETED', $first->json('model_run.status'));
        $this->assertSame(['invoices' => 5, 'total_cents' => 100_000], json_decode($first->json('model_run.model_output'), true));

        $second = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", $body, ['Idempotency-Key' => 'test-idem-dp-run-0001']);
        $second->assertStatus(201);
        $this->assertSame($first->json('model_run.id'), $second->json('model_run.id'));
        $this->assertDatabaseCount('analytics_model_runs', 1);
    }

    public function test_publishing_requires_national_scope(): void
    {
        $admin = $this->pilotAdmin();
        $tp = $this->makeTaxpayer('VAT-DP-0003');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'publish-denied@dptest.test');
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);
        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 5], $admin->id);
        $modelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $runId]), ['Idempotency-Key' => 'test-idem-dp-pubscope-run-0001'])
            ->json('model_run.id');

        $this->actingAs($owner)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-pubscope-0001'])
            ->assertStatus(403);
    }

    public function test_publishing_requires_an_unknown_model_run_a_completed_status_and_refuses_a_second_publish(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);
        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 5], $admin->id);

        // Unknown model run for this data product.
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => (string) Str::uuid()]), ['Idempotency-Key' => 'test-idem-dp-pub-unknown-0001'])
            ->assertStatus(404);

        // A model run stuck in a non-COMPLETED state (no command in this codebase ever produces one --
        // runModel() always inserts status='COMPLETED' -- this defensively covers a future async model run).
        $stuckModelRunId = (string) Str::uuid();
        DB::table('analytics_model_runs')->insert([
            'id' => $stuckModelRunId, 'data_product_id' => $productId, 'report_run_id' => $runId, 'status' => 'FAILED',
            'model_output' => json_encode(['invoices' => 5]), 'requested_by' => $admin->id, 'requested_at' => now(),
        ]);
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $stuckModelRunId]), ['Idempotency-Key' => 'test-idem-dp-pub-stuck-0001'])
            ->assertStatus(409);

        $modelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $runId]), ['Idempotency-Key' => 'test-idem-dp-pub-run-0001'])
            ->json('model_run.id');
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-pub-first-0001'])
            ->assertStatus(201);

        // Republishing the SAME model run under a fresh idempotency key is a genuine conflict, not a replay.
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-pub-second-0001'])
            ->assertStatus(409);
    }

    public function test_the_first_ever_publish_never_raises_an_anomaly_even_with_a_certified_metric(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);
        $this->seedMetric($productId, 'INVOICE_COUNT', 'invoices', 'CERTIFIED', 10.0);
        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 1_000], $admin->id);
        $modelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $runId]), ['Idempotency-Key' => 'test-idem-dp-firstpub-run-0001'])
            ->json('model_run.id');

        $response = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-firstpub-0001']);

        $response->assertStatus(201);
        $this->assertNull($response->json('snapshot.previous_snapshot_id'));
        $this->assertDatabaseCount('analytics_anomaly_candidates', 0);
    }

    public function test_publishing_a_second_snapshot_raises_an_anomaly_for_a_certified_metric_exceeding_its_threshold(): void
    {
        $admin = $this->pilotAdmin();
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId);
        $this->seedMetric($productId, 'INVOICE_COUNT', 'invoices', 'CERTIFIED', 10.0);

        $firstRunId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 100], $admin->id);
        $firstModelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $firstRunId]), ['Idempotency-Key' => 'test-idem-dp-anomaly-run1-0001'])
            ->json('model_run.id');
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $firstModelRunId]), ['Idempotency-Key' => 'test-idem-dp-anomaly-pub1-0001'])
            ->assertStatus(201);

        // A 50% jump exceeds the 10% threshold.
        $secondRunId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 150], $admin->id);
        $secondModelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $secondRunId]), ['Idempotency-Key' => 'test-idem-dp-anomaly-run2-0001'])
            ->json('model_run.id');
        $response = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $secondModelRunId]), ['Idempotency-Key' => 'test-idem-dp-anomaly-pub2-0001']);

        $response->assertStatus(201);
        $this->assertDatabaseCount('analytics_anomaly_candidates', 1);
        $anomaly = DB::table('analytics_anomaly_candidates')->first();
        $this->assertSame('INVOICE_COUNT', $anomaly->metric_code);
        $this->assertEqualsWithDelta(50.0, (float) $anomaly->pct_change, 0.001);

        $anomalies = $this->actingAs($admin)->getJson("/api/v1/analytics/anomalies?data_product_id={$productId}");
        $anomalies->assertStatus(200);
        $this->assertCount(1, $anomalies->json('anomalies'));
    }

    public function test_approved_metrics_reports_available_or_no_data_and_is_filterable(): void
    {
        $admin = $this->pilotAdmin();
        $tp = $this->makeTaxpayer('VAT-DP-0004');
        $denied = $this->sellerAdmin($tp['taxpayer']->id, 'metrics-denied@dptest.test');
        $definitionId = $this->seedReportDefinition('SALES_VAT_SUMMARY');
        $productId = $this->seedDataProduct($definitionId, 'METRICS_PRODUCT');
        $this->seedMetric($productId, 'INVOICE_COUNT', 'invoices', 'CERTIFIED');
        $this->seedMetric($productId, 'NOT_YET_CERTIFIED', 'total_cents', 'DRAFT');

        $this->actingAs($denied)->getJson('/api/v1/analytics/metrics')->assertStatus(403);

        $noData = $this->actingAs($admin)->getJson("/api/v1/analytics/metrics?data_product_id={$productId}");
        $noData->assertStatus(200);
        $metrics = $noData->json('metrics');
        $this->assertCount(1, $metrics);
        $this->assertSame('NO_DATA', $metrics[0]['status']);
        $this->assertNull($metrics[0]['value']);

        $runId = $this->seedReportRun($definitionId, 'PUBLISHED', ['invoices' => 42], $admin->id);
        $modelRunId = $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/model-runs", array_merge($this->schemaBody(), ['report_run_id' => $runId]), ['Idempotency-Key' => 'test-idem-dp-metrics-run-0001'])
            ->json('model_run.id');
        $this->actingAs($admin)->postJson("/api/v1/analytics/data-products/{$productId}/publications", array_merge($this->schemaBody(), ['model_run_id' => $modelRunId]), ['Idempotency-Key' => 'test-idem-dp-metrics-pub-0001'])
            ->assertStatus(201);

        $available = $this->actingAs($admin)->getJson("/api/v1/analytics/metrics?code=INVOICE_COUNT");
        $available->assertStatus(200);
        $metrics = $available->json('metrics');
        $this->assertCount(1, $metrics);
        $this->assertSame('AVAILABLE', $metrics[0]['status']);
        $this->assertSame(42, $metrics[0]['value']);
    }

    public function test_anomalies_require_the_reports_read_permission_and_are_unfiltered_without_a_data_product_id(): void
    {
        $tp = $this->makeTaxpayer('VAT-DP-0005');
        $denied = $this->sellerAdmin($tp['taxpayer']->id, 'anomalies-denied@dptest.test');

        $this->actingAs($denied)->getJson('/api/v1/analytics/anomalies')->assertStatus(403);

        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->getJson('/api/v1/analytics/anomalies')->assertStatus(200)->assertJsonPath('anomalies', []);
    }
}

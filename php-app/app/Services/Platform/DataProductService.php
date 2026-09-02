<?php

namespace App\Services\Platform;

use App\Domain\Platform\DataProductValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/platform-repository.ts's listDataProducts/
 * runAnalyticsModel/publishDataProduct/queryApprovedMetrics/
 * listAnomalyCandidates -- Module 7 Phase D, Phase 13's fifth slice.
 * Still genuinely separate and NOT STARTED: platform config/
 * change-management (Module 8 Phase A onward).
 *
 * Analytics is greenfield in the source -- a documented 2026-08-26 audit
 * found nothing beyond an "ARCHITECTURE ONLY" label. This deployment has
 * no separate governed read replica/warehouse (the same MySQL database
 * backs both the live fiscal write path and every read), so "RunModel
 * against a governed read replica only, never the live fiscal write
 * store" is built as the strongest real analog available: a data
 * product's RunModel step may only be fed by an already-`PUBLISHED`,
 * already-reconciled `report_runs` row (Phase C's `publishReportRun`,
 * see App\Services\Platform\ReportExportService) -- never a live query
 * against invoices/vat_return_versions/audit_cases/etc. `runModel()`/
 * `publish()` only ever read `report_runs`/`report_definitions`/
 * `data_products`/`metrics`/`analytics_model_runs`/
 * `data_product_snapshots` -- never a fiscal source table directly.
 * `DataProduct`/`Metric`/lineage definitions are deliberately seed-only
 * (no command creates one), the same posture Phase C's `report_definitions`
 * already established: defining a new governed metric is a governance/
 * config action out of scope for this pilot.
 *
 * No Eloquent model for any of the six tables this service touches
 * (`data_products`/`data_product_lineage`/`metrics`/
 * `analytics_model_runs`/`data_product_snapshots`/
 * `analytics_anomaly_candidates`) -- `DB::table()` throughout, matching
 * every other Phase 13 slice's own established style.
 */
class DataProductService
{
    private const SUPPRESSED_RESULT_MESSAGE = 'The source report run is minimum-cell suppressed and cannot feed a certified analytics model.';

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $products = DB::table('data_products as dp')
            ->join('report_definitions as rd', 'rd.id', '=', 'dp.source_report_definition_id')
            ->where('dp.status', 'ACTIVE')->orderBy('dp.code')
            ->select('dp.id', 'dp.code', 'dp.name', 'dp.description', 'dp.status', 'rd.code as source_report_code', 'rd.name as source_report_name')
            ->get();

        return $products->map(function ($product) {
            $lineage = DB::table('data_product_lineage')->where('data_product_id', $product->id)
                ->orderBy('recorded_at')->select('source_type', 'source_id', 'source_label')->get();
            $metrics = DB::table('metrics')->where('data_product_id', $product->id)
                ->orderBy('code')->select('code', 'name', 'field', 'unit', 'status')->get();
            $latestSnapshot = DB::table('data_product_snapshots')->where('data_product_id', $product->id)
                ->orderByDesc('published_at')->select('id', 'snapshot', 'published_by', 'published_at')->first();

            return [
                'id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'description' => $product->description,
                'source' => ['report_code' => $product->source_report_code, 'report_name' => $product->source_report_name],
                'lineage' => $lineage->map(fn ($row) => (array) $row)->values()->all(),
                'certified_metrics' => $metrics->filter(fn ($m) => $m->status === 'CERTIFIED')->map(fn ($m) => (array) $m)->values()->all(),
                'latest_snapshot' => $latestSnapshot ? [
                    'id' => $latestSnapshot->id, 'snapshot' => json_decode($latestSnapshot->snapshot, true),
                    'published_by' => $latestSnapshot->published_by, 'published_at' => $latestSnapshot->published_at,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Module 7 Phase D RunModel. National-only (the same posture as
     * CompleteDocumentScan/SetDocumentRetentionHold/ApproveReportExport):
     * this represents a governed analytics pipeline step, not a taxpayer
     * self-service action.
     *
     * @return array<string, mixed>
     */
    public function runModel(string $dataProductId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national platform role may run an analytics model.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DataProductValidator::runModel($payload);
        $requestHash = CommandLedger::requestHash(['data_product_id' => $dataProductId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RUN_ANALYTICS_MODEL', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('analytics_model_runs')->where('id', $prior)->first();
        }

        $dataProduct = DB::table('data_products')->where('id', $dataProductId)->where('status', 'ACTIVE')
            ->select('id', 'source_report_definition_id')->first();
        if (! $dataProduct) {
            throw new PlatformResourceException('Data product was not found.', 404);
        }
        $reportRun = DB::table('report_runs')->where('id', $input['report_run_id'])
            ->select('id', 'report_definition_id', 'status', 'result_summary')->first();
        if (! $reportRun) {
            throw new PlatformResourceException('Report run was not found.', 404);
        }
        if ($reportRun->report_definition_id !== $dataProduct->source_report_definition_id) {
            throw new PlatformResourceException("The report run does not match this data product's governed source report definition.");
        }
        if ($reportRun->status !== 'PUBLISHED') {
            throw new RepositoryConflictException('Only a published, reconciled report run may feed an analytics model.');
        }
        $sourceResult = json_decode($reportRun->result_summary, true) ?? [];
        if (($sourceResult['suppressed'] ?? null) === true) {
            throw new RepositoryConflictException(self::SUPPRESSED_RESULT_MESSAGE);
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $dataProductId, $input, $sourceResult, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('analytics_model_runs')->insert([
                'id' => $id, 'data_product_id' => $dataProductId, 'report_run_id' => $input['report_run_id'],
                'status' => 'COMPLETED', 'model_output' => json_encode($sourceResult), 'requested_by' => $actor->id, 'requested_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RUN_ANALYTICS_MODEL', $idempotencyKey, $requestHash, 'ANALYTICS_MODEL_RUN', $id, $now);
            AuditService::append($actor, 'ANALYTICS_MODEL_RUN', 'ANALYTICS_MODEL_RUN', $id, [
                'dataProductId' => $dataProductId, 'reportRunId' => $input['report_run_id'], 'correlationId' => $correlationId,
            ], $now);
        });

        return (array) DB::table('analytics_model_runs')->where('id', $id)->first();
    }

    /**
     * Module 7 Phase D PublishDataProduct: promotes a completed ModelRun to
     * be the data product's current snapshot, then checks every CERTIFIED
     * metric on this data product against the previous snapshot's value. A
     * percentage change at or beyond the metric's own
     * `anomaly_threshold_pct` raises a genuine, explainable
     * AnomalyCandidate -- persisted as a queryable row
     * (`analytics_anomaly_candidates`), not just a fire-and-forget event,
     * matching Module 4's "always return the explainable factor"
     * precedent for risk indicators. The first-ever publish for a data
     * product has nothing to compare against, so it never raises an
     * anomaly.
     *
     * @return array<string, mixed>
     */
    public function publish(string $dataProductId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national platform role may publish a data product.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DataProductValidator::publishDataProduct($payload);
        $requestHash = CommandLedger::requestHash(['data_product_id' => $dataProductId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'PUBLISH_DATA_PRODUCT', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('data_product_snapshots')->where('id', $prior)->first();
        }

        $dataProduct = DB::table('data_products')->where('id', $dataProductId)->where('status', 'ACTIVE')->select('id')->first();
        if (! $dataProduct) {
            throw new PlatformResourceException('Data product was not found.', 404);
        }
        $modelRun = DB::table('analytics_model_runs')->where('id', $input['model_run_id'])->where('data_product_id', $dataProductId)
            ->select('id', 'model_output', 'status')->first();
        if (! $modelRun) {
            throw new PlatformResourceException('Model run was not found for this data product.', 404);
        }
        if ($modelRun->status !== 'COMPLETED') {
            throw new RepositoryConflictException('Only a completed model run can be published.');
        }
        $alreadyPublished = DB::table('data_product_snapshots')->where('model_run_id', $input['model_run_id'])->select('id')->first();
        if ($alreadyPublished) {
            throw new RepositoryConflictException('This model run has already been published.');
        }
        $previous = DB::table('data_product_snapshots')->where('data_product_id', $dataProductId)
            ->orderByDesc('published_at')->select('id', 'snapshot')->first();

        $now = now();
        $snapshotId = (string) Str::uuid();
        $modelOutput = json_decode($modelRun->model_output, true) ?? [];
        $previousOutput = $previous ? (json_decode($previous->snapshot, true) ?? []) : null;

        $certifiedMetrics = DB::table('metrics')->where('data_product_id', $dataProductId)->where('status', 'CERTIFIED')
            ->select('code', 'field', 'anomaly_threshold_pct')->get();
        $anomalies = [];
        foreach ($certifiedMetrics as $metric) {
            $currentValue = $this->numeric($modelOutput[$metric->field] ?? null);
            $previousValue = $previousOutput !== null ? $this->numeric($previousOutput[$metric->field] ?? null) : null;
            if ($previousValue === null || $previousValue === 0.0 || $currentValue === null) {
                continue;
            }
            $pctChange = (($currentValue - $previousValue) / abs($previousValue)) * 100;
            if (abs($pctChange) < (float) $metric->anomaly_threshold_pct) {
                continue;
            }
            $anomalies[] = [
                'id' => (string) Str::uuid(), 'metric_code' => $metric->code, 'previous_value' => $previousValue,
                'current_value' => $currentValue, 'pct_change' => $pctChange, 'threshold_pct' => (float) $metric->anomaly_threshold_pct,
            ];
        }

        DB::transaction(function () use ($snapshotId, $dataProductId, $input, $modelOutput, $previous, $actor, $now, $anomalies, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('data_product_snapshots')->insert([
                'id' => $snapshotId, 'data_product_id' => $dataProductId, 'model_run_id' => $input['model_run_id'],
                'snapshot' => json_encode($modelOutput), 'previous_snapshot_id' => $previous->id ?? null,
                'published_by' => $actor->id, 'published_at' => $now,
            ]);
            foreach ($anomalies as $anomaly) {
                DB::table('analytics_anomaly_candidates')->insert([
                    'id' => $anomaly['id'], 'data_product_snapshot_id' => $snapshotId, 'metric_code' => $anomaly['metric_code'],
                    'previous_value' => $anomaly['previous_value'], 'current_value' => $anomaly['current_value'],
                    'pct_change' => $anomaly['pct_change'], 'threshold_pct' => $anomaly['threshold_pct'], 'detected_at' => $now,
                ]);
                CommandLedger::outbox('DATA_PRODUCT', $dataProductId, 'AnomalyCandidate', $dataProductId, [
                    'data_product_id' => $dataProductId, 'snapshot_id' => $snapshotId, 'metric_code' => $anomaly['metric_code'],
                    'previous_value' => $anomaly['previous_value'], 'current_value' => $anomaly['current_value'],
                    'pct_change' => $anomaly['pct_change'], 'threshold_pct' => $anomaly['threshold_pct'], 'correlation_id' => $correlationId,
                ], $now);
            }
            CommandLedger::record($actor->id, 'PUBLISH_DATA_PRODUCT', $idempotencyKey, $requestHash, 'DATA_PRODUCT_SNAPSHOT', $snapshotId, $now);
            CommandLedger::outbox('DATA_PRODUCT', $dataProductId, 'AnalyticsRefreshed', $dataProductId, [
                'data_product_id' => $dataProductId, 'snapshot_id' => $snapshotId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'DATA_PRODUCT_PUBLISHED', 'DATA_PRODUCT_SNAPSHOT', $snapshotId, [
                'dataProductId' => $dataProductId, 'modelRunId' => $input['model_run_id'], 'anomalies' => count($anomalies), 'correlationId' => $correlationId,
            ], $now);
        });

        return (array) DB::table('data_product_snapshots')->where('id', $snapshotId)->first();
    }

    /** Module 7 Phase D QueryApprovedMetrics: reads only `metrics`/`data_product_snapshots`, never a live fiscal table. */
    public function approvedMetrics(?string $dataProductId, ?string $code): array
    {
        $query = DB::table('metrics as m')->join('data_products as dp', 'dp.id', '=', 'm.data_product_id')->where('m.status', 'CERTIFIED');
        if ($dataProductId) {
            $query->where('m.data_product_id', $dataProductId);
        }
        if ($code) {
            $query->where('m.code', mb_strtoupper($code));
        }
        $metrics = $query->orderBy('m.code')
            ->select('m.code', 'm.name', 'm.unit', 'm.field', 'm.data_product_id', 'dp.code as data_product_code', 'dp.name as data_product_name')
            ->get();

        return $metrics->map(function ($metric) {
            $snapshot = DB::table('data_product_snapshots')->where('data_product_id', $metric->data_product_id)
                ->orderByDesc('published_at')->select('snapshot', 'published_at')->first();
            $value = null;
            if ($snapshot) {
                $decoded = json_decode($snapshot->snapshot, true) ?? [];
                $value = $decoded[$metric->field] ?? null;
            }

            return [
                'code' => $metric->code, 'name' => $metric->name, 'unit' => $metric->unit,
                'data_product_code' => $metric->data_product_code, 'data_product_name' => $metric->data_product_name,
                'value' => $value, 'as_of' => $snapshot->published_at ?? null, 'status' => $snapshot ? 'AVAILABLE' : 'NO_DATA',
            ];
        })->values()->all();
    }

    /** Module 7 Phase D: queryable AnomalyCandidate list, not just a fire-and-forget outbox event. */
    public function anomalyCandidates(?string $dataProductId): array
    {
        $query = DB::table('analytics_anomaly_candidates as a')
            ->join('data_product_snapshots as s', 's.id', '=', 'a.data_product_snapshot_id')
            ->select('a.id', 'a.metric_code', 'a.previous_value', 'a.current_value', 'a.pct_change', 'a.threshold_pct', 'a.detected_at', 's.data_product_id');
        if ($dataProductId) {
            $query->where('s.data_product_id', $dataProductId);
        }

        return $query->orderByDesc('a.detected_at')->limit(200)->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    /** Mirrors JS's `Number(value)` + `Number.isFinite` -- null for anything that isn't a genuine finite number. */
    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (float) $value;

            return is_finite($n) ? $n : null;
        }

        return null;
    }
}

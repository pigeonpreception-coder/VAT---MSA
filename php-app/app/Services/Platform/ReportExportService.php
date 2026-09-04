<?php

namespace App\Services\Platform;

use App\Domain\Document\DocumentValidator;
use App\Domain\Platform\ReportValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use App\Support\Platform\PlatformConfigReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/platform-repository.ts's runInlineReport/
 * publishReportRun/requestReportExport/approveReportExport/
 * cancelReportExport/getReportExport/downloadReportExport -- Module 7
 * Phases A-C, the fourth slice of Phase 13 after Document module,
 * Platform snapshots and Offline sync commands. Still genuinely separate
 * sub-modules of `platform-repository.ts`, still NOT STARTED: data
 * products/analytics, platform config/change-management.
 *
 * No Eloquent model for `report_definitions`/`report_runs`/
 * `report_exports` yet -- `DB::table()` throughout, matching this phase's
 * own established style. `document_metadata` DOES have a model
 * (`App\Models\DocumentMetadata`), but a report export's own row is
 * written via `DB::table()` here too, matching `requestReportExport`'s own
 * single mixed-table transaction shape rather than mixing an Eloquent
 * write into an otherwise-DB::table() command.
 *
 * `env.DOCUMENTS` (Cloudflare R2 in the source) is Laravel's own `local`
 * filesystem disk here, same substitution `App\Services\Document\
 * DocumentService` already established -- an export's object key is
 * `exports/{organisation_id}/{document_id}/{file_name}`, distinct from a
 * regular upload's `quarantine/...` prefix, matching the source exactly.
 */
class ReportExportService
{
    private const RUNNABLE_CODES = [
        'PORTFOLIO_EXCEPTIONS', 'REVENUE_COMPLIANCE_TRENDS', 'NATIONAL_VAT_AGGREGATE',
    ];

    private const SENSITIVE_CLASSIFICATIONS = ['TAX_CONFIDENTIAL', 'RESTRICTED'];

    /** Fallback only -- read live via PlatformConfigReader::int('reports.export_size_limit_bytes', ...) below when an ACTIVE platform_config row exists. */
    private const EXPORT_SIZE_LIMIT_BYTES_DEFAULT = 200 * 1_024;

    private const EXPORT_EXPIRY_SECONDS = 7 * 24 * 60 * 60;

    /** Fallback only -- read live via PlatformConfigReader::int('reports.min_cell_suppression_threshold', ...) below when an ACTIVE platform_config row exists. */
    private const MIN_CELL_SUPPRESSION_THRESHOLD_DEFAULT = 10;

    private const CURRENCY_BASIS = 'NAD';

    public function __construct(private readonly OrganisationResolver $organisations) {}

    /**
     * Module 7 Phase A RunReport: computes and persists one inline report
     * run. `PORTFOLIO_EXCEPTIONS`/`REVENUE_COMPLIANCE_TRENDS`/
     * `NATIONAL_VAT_AGGREGATE` are always run unscoped (portfolio/national
     * aggregates by definition); `CASE_EVIDENCE_SUMMARY` re-derives its own
     * scope from the requested `case_id`, checked against the actor's own
     * taxpayer scope; every other code uses the actor's own resolved
     * organisation/taxpayer.
     *
     * @return array<string, mixed>
     */
    public function runInline(string $code, array $parametersInput, User $actor): array
    {
        $parameters = ReportValidator::parameters($parametersInput);
        $definition = DB::table('report_definitions')->where('code', mb_strtoupper($code))->where('status', 'ACTIVE')->first();
        if (! $definition) {
            throw new PlatformResourceException('Report definition was not found.', 404);
        }
        $guardrail = $this->requireAudienceAccess($definition, $actor);
        $orgScope = TenantScope::isNational($actor) ? null : $this->organisations->resolve($actor, null);
        $taxpayerIdForRun = $orgScope?->taxpayer_id;
        $organisationIdForRun = $orgScope?->id;
        $caseId = null;

        if (in_array($definition->code, self::RUNNABLE_CODES, true)) {
            $taxpayerIdForRun = null;
            $organisationIdForRun = null;
        } elseif ($definition->code === 'CASE_EVIDENCE_SUMMARY') {
            $caseId = is_string($parameters['case_id'] ?? null) ? trim($parameters['case_id']) : '';
            if ($caseId === '') {
                throw new PlatformResourceException('case_id is required for this report.');
            }
            $auditCase = DB::table('audit_cases')->where('id', $caseId)->select('id', 'taxpayer_id', 'organisation_id')->first();
            if (! $auditCase) {
                throw new PlatformResourceException('Audit case was not found.', 404);
            }
            // Faithful-port note, like PlatformSnapshotService::getSnapshot's
            // own `$scoped` branch: unreachable by any role seeded today.
            // Reaching CASE_EVIDENCE_SUMMARY at all already requires
            // audit:read/cases:manage (requireAudienceAccess below), and
            // every role holding either is also a NATIONAL_SCOPE_ROLES
            // member -- so TenantScope::isNational($actor) is always true
            // here. Preserved anyway for a future role grant, not pruned.
            if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $auditCase->taxpayer_id) {
                throw new AuthorizationException('The audit case is outside your authorised taxpayer scope.');
            }
            $taxpayerIdForRun = $auditCase->taxpayer_id;
            $organisationIdForRun = $auditCase->organisation_id;
        }

        $scope = [
            'organisation_id' => $organisationIdForRun, 'taxpayer_id' => $taxpayerIdForRun,
            'delegated_taxpayer_ids' => $guardrail['delegated_taxpayer_ids'] ?? null, 'case_id' => $caseId,
        ];
        $resultSummary = $this->computeReportResult($definition->code, $scope);

        $id = (string) Str::uuid();
        $now = now();
        DB::table('report_runs')->insert([
            'id' => $id, 'report_definition_id' => $definition->id, 'organisation_id' => $organisationIdForRun,
            'taxpayer_id' => $taxpayerIdForRun, 'parameters' => json_encode($parameters), 'status' => 'COMPLETED_INLINE',
            'row_count' => count($resultSummary), 'result_summary' => json_encode($resultSummary), 'output_document_id' => null,
            'requested_by' => $actor->id, 'requested_at' => $now, 'completed_at' => $now, 'expires_at' => $now->copy()->addDay(),
            'error_code' => null, 'scope_snapshot' => json_encode($scope), 'published_by' => null, 'published_at' => null,
        ]);

        return [
            'id' => $id, 'report_code' => $definition->code, 'status' => 'COMPLETED_INLINE',
            'envelope' => $this->buildEnvelope($definition, $parameters, $now), 'result_summary' => $resultSummary,
            'requested_at' => $now->toISOString(),
        ];
    }

    /**
     * Module 7 Phase C PublishReport: the "reconciles to source control
     * totals" hard publication gate. `computeReportResult` is re-run
     * against the exact scope the original run used (persisted in
     * `scope_snapshot`, since e.g. a PRACTITIONER-tier report's delegated-
     * taxpayer set is resolved once at run time and cannot be safely
     * re-derived from whoever happens to call this) and compared to the
     * stored `result_summary`; a divergence refuses publication (409),
     * forcing a fresh run before the figure can become official.
     *
     * @return array<string, mixed>
     */
    public function publish(string $reportRunId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        ReportValidator::exportCommand($payload);
        $requestHash = CommandLedger::requestHash(['report_run_id' => $reportRunId]);
        $prior = CommandLedger::prior($actor->id, 'PUBLISH_REPORT_RUN', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('report_runs')->where('id', $prior)->first();
        }

        $run = DB::table('report_runs as r')->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
            ->where('r.id', $reportRunId)
            ->select('r.*', 'd.code', 'd.audience', 'd.freshness_tier', 'd.guardrail', 'd.query_version')
            ->first();
        if (! $run) {
            throw new PlatformResourceException('Report run was not found.', 404);
        }
        if (! TenantScope::isNational($actor) && $run->requested_by !== $actor->id) {
            throw new AuthorizationException('You may only publish a report run you requested.');
        }
        if ($run->status !== 'COMPLETED_INLINE') {
            throw new RepositoryConflictException($run->status === 'PUBLISHED' ? 'This report run has already been published.' : 'Only a completed report run can be published.');
        }
        $scope = $run->scope_snapshot ? json_decode($run->scope_snapshot, true) : ['organisation_id' => $run->organisation_id, 'taxpayer_id' => $run->taxpayer_id];
        $parameters = json_decode($run->parameters, true) ?? [];
        $liveResult = $this->computeReportResult($run->code, $scope);
        $storedResult = json_decode($run->result_summary, true) ?? [];
        if (AuditService::canonicalJson($liveResult) !== AuditService::canonicalJson($storedResult)) {
            throw new RepositoryConflictException('The underlying data has changed since this report run completed; run the report again before publishing.');
        }

        $now = now();
        DB::transaction(function () use ($reportRunId, $actor, $now, $idempotencyKey, $requestHash, $run, $correlationId) {
            DB::table('report_runs')->where('id', $reportRunId)->where('status', 'COMPLETED_INLINE')
                ->update(['status' => 'PUBLISHED', 'published_by' => $actor->id, 'published_at' => $now]);
            CommandLedger::record($actor->id, 'PUBLISH_REPORT_RUN', $idempotencyKey, $requestHash, 'REPORT_RUN', $reportRunId, $now);
            CommandLedger::outbox('REPORT_RUN', $reportRunId, 'ReportRunPublished', $run->taxpayer_id ?? $reportRunId, ['report_run_id' => $reportRunId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'REPORT_RUN_PUBLISHED', 'REPORT_RUN', $reportRunId, ['code' => $run->code, 'correlationId' => $correlationId], $now);
        });

        return [
            'id' => $reportRunId, 'report_code' => $run->code, 'status' => 'PUBLISHED',
            'envelope' => $this->buildEnvelope($run, $parameters, $now), 'result_summary' => $storedResult,
            'requested_at' => $run->requested_at, 'published_at' => $now->toISOString(),
        ];
    }

    /**
     * Module 7 Phase B RequestExport: generates the export file inline
     * (this migration has no queue/cron infrastructure to defer it onto,
     * matching the source's own reasoning) and stores it as a
     * `document_metadata` row, reusing Module 6's QUARANTINED/ACTIVE/
     * REJECTED lifecycle as the approval gate: a sensitive report's export
     * starts QUARANTINED (not downloadable) until ApproveExport; a
     * non-sensitive report's export is created directly ACTIVE
     * (auto-approved, no human gate needed).
     *
     * @return array<string, mixed>
     */
    public function requestExport(string $reportRunId, array $payload, User $actor, string $idempotencyKey, string $correlationId, bool $hasFreshStepUp): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        ReportValidator::exportCommand($payload);
        $requestHash = CommandLedger::requestHash(['report_run_id' => $reportRunId]);
        $prior = CommandLedger::prior($actor->id, 'REQUEST_REPORT_EXPORT', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('report_exports')->where('id', $prior)->first();
        }

        $run = $this->loadRunForExport($reportRunId);
        if (! TenantScope::isNational($actor) && $run->requested_by !== $actor->id) {
            throw new AuthorizationException('You may only export a report run you requested.');
        }
        if (! in_array($run->status, ['COMPLETED_INLINE', 'PUBLISHED'], true)) {
            throw new RepositoryConflictException('Only a completed report run can be exported.');
        }
        $scope = $this->organisations->resolve($actor, $run->organisation_id);
        $sensitive = in_array($run->classification, self::SENSITIVE_CLASSIFICATIONS, true);
        if ($sensitive && ! $hasFreshStepUp) {
            throw new AuthorizationException('Exporting a report of this classification requires a fresh step-up authentication.');
        }

        $now = now();
        $watermark = "issued_to:{$actor->id} at:{$now->toISOString()} correlation:{$correlationId}";
        $bytes = $this->buildExportContent($run, $watermark);
        $exportSizeLimitBytes = PlatformConfigReader::int('reports.export_size_limit_bytes', self::EXPORT_SIZE_LIMIT_BYTES_DEFAULT);
        if (strlen($bytes) > $exportSizeLimitBytes) {
            throw new PlatformResourceException('The generated export exceeds the maximum allowed size.', 413);
        }
        $fileName = DocumentValidator::safeFileName("{$run->code}-{$run->id}.csv");
        $documentId = (string) Str::uuid();
        $objectKey = "exports/{$scope->id}/{$documentId}/{$fileName}";
        $documentStatus = $sensitive ? 'QUARANTINED' : 'ACTIVE';
        $checksum = hash('sha256', $bytes);
        $exportId = (string) Str::uuid();
        $expiresAt = $now->copy()->addSeconds(self::EXPORT_EXPIRY_SECONDS);

        Storage::disk('local')->put($objectKey, $bytes);
        try {
            DB::transaction(function () use (
                $documentId, $scope, $run, $objectKey, $fileName, $bytes, $checksum, $documentStatus, $actor, $now,
                $exportId, $sensitive, $watermark, $expiresAt, $idempotencyKey, $requestHash, $correlationId
            ) {
                DB::table('document_metadata')->insert([
                    'id' => $documentId, 'organisation_id' => $scope->id, 'owner_domain' => 'REPORT_EXPORT', 'owner_resource_id' => $run->id,
                    'object_key' => $objectKey, 'file_name' => $fileName, 'content_type' => 'text/csv', 'size_bytes' => strlen($bytes),
                    'checksum_sha256' => $checksum, 'classification' => $run->classification, 'scan_status' => 'CLEAN',
                    'status' => $documentStatus, 'uploaded_by' => $actor->id, 'uploaded_at' => $now, 'retained_until' => null,
                    'legal_hold' => false, 'scanned_by' => null, 'scanned_at' => null, 'supersedes_document_id' => null,
                ]);
                DB::table('report_exports')->insert([
                    'id' => $exportId, 'report_run_id' => $run->id, 'document_id' => $documentId, 'status' => $sensitive ? 'PENDING_APPROVAL' : 'APPROVED',
                    'requires_step_up' => $sensitive, 'watermark' => $watermark, 'requested_by' => $actor->id, 'requested_at' => $now,
                    'approved_by' => $sensitive ? null : $actor->id, 'approved_at' => $sensitive ? null : $now,
                    'cancelled_by' => null, 'cancelled_at' => null, 'cancellation_reason' => null, 'expires_at' => $expiresAt,
                ]);
                CommandLedger::record($actor->id, 'REQUEST_REPORT_EXPORT', $idempotencyKey, $requestHash, 'REPORT_EXPORT', $exportId, $now);
                CommandLedger::outbox('REPORT_EXPORT', $exportId, $sensitive ? 'ReportExportPendingApproval' : 'ReportExportApproved', $scope->taxpayer_id, [
                    'export_id' => $exportId, 'report_run_id' => $run->id, 'sensitive' => $sensitive, 'correlation_id' => $correlationId,
                ], $now);
                AuditService::append($actor, 'REPORT_EXPORT_REQUESTED', 'REPORT_EXPORT', $exportId, [
                    'reportRunId' => $run->id, 'classification' => $run->classification, 'sensitive' => $sensitive, 'correlationId' => $correlationId,
                ], $now);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($objectKey);
            throw $e;
        }

        return (array) DB::table('report_exports')->where('id', $exportId)->first();
    }

    /**
     * Module 7 Phase B ApproveExport: maker-checker gate on a sensitive
     * export. Restricted to national platform roles (the same posture as
     * CompleteDocumentScan/SetDocumentRetentionHold) and refuses the
     * requester's own request.
     *
     * @return array<string, mixed>
     */
    public function approveExport(string $exportId, array $payload, User $actor, string $idempotencyKey, string $correlationId, bool $hasFreshStepUp): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national platform role may approve a report export.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        ReportValidator::exportCommand($payload);
        $requestHash = CommandLedger::requestHash(['export_id' => $exportId]);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_REPORT_EXPORT', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('report_exports')->where('id', $prior)->first();
        }

        $row = DB::table('report_exports')->where('id', $exportId)->first();
        if (! $row) {
            throw new PlatformResourceException('Report export was not found.', 404);
        }
        if ($row->status !== 'PENDING_APPROVAL') {
            throw new RepositoryConflictException('Only a pending report export can be approved.');
        }
        if ($row->requested_by === $actor->id) {
            throw new AuthorizationException('You may not approve a report export you requested yourself.');
        }
        if ($row->requires_step_up && ! $hasFreshStepUp) {
            throw new AuthorizationException('Approving this report export requires a fresh step-up authentication.');
        }

        $now = now();
        DB::transaction(function () use ($exportId, $actor, $now, $row, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('report_exports')->where('id', $exportId)->where('status', 'PENDING_APPROVAL')
                ->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => $now]);
            DB::table('document_metadata')->where('id', $row->document_id)->where('status', 'QUARANTINED')->update(['status' => 'ACTIVE']);
            CommandLedger::record($actor->id, 'APPROVE_REPORT_EXPORT', $idempotencyKey, $requestHash, 'REPORT_EXPORT', $exportId, $now);
            CommandLedger::outbox('REPORT_EXPORT', $exportId, 'ReportExportApproved', $exportId, ['export_id' => $exportId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'REPORT_EXPORT_APPROVED', 'REPORT_EXPORT', $exportId, ['correlationId' => $correlationId], $now);
        });

        return (array) DB::table('report_exports')->where('id', $exportId)->first();
    }

    /**
     * Module 7 Phase B CancelReport: withdraws a still-pending export,
     * either by the original requester or an authorised national role.
     *
     * @return array<string, mixed>
     */
    public function cancelExport(string $exportId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ReportValidator::exportCancellation($payload);
        $requestHash = CommandLedger::requestHash(['export_id' => $exportId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CANCEL_REPORT_EXPORT', $idempotencyKey, $requestHash);
        if ($prior) {
            return (array) DB::table('report_exports')->where('id', $prior)->first();
        }

        $row = DB::table('report_exports')->where('id', $exportId)->first();
        if (! $row) {
            throw new PlatformResourceException('Report export was not found.', 404);
        }
        if (! TenantScope::isNational($actor) && $row->requested_by !== $actor->id) {
            throw new AuthorizationException('You may only cancel a report export you requested.');
        }
        if ($row->status !== 'PENDING_APPROVAL') {
            throw new RepositoryConflictException('Only a pending report export can be cancelled.');
        }

        $now = now();
        DB::transaction(function () use ($exportId, $actor, $now, $row, $input, $idempotencyKey, $requestHash, $correlationId) {
            DB::table('report_exports')->where('id', $exportId)->where('status', 'PENDING_APPROVAL')
                ->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => $now, 'cancellation_reason' => $input['reason']]);
            DB::table('document_metadata')->where('id', $row->document_id)->where('status', 'QUARANTINED')->update(['status' => 'REJECTED']);
            CommandLedger::record($actor->id, 'CANCEL_REPORT_EXPORT', $idempotencyKey, $requestHash, 'REPORT_EXPORT', $exportId, $now);
            CommandLedger::outbox('REPORT_EXPORT', $exportId, 'ReportExportCancelled', $exportId, ['export_id' => $exportId, 'reason' => $input['reason'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'REPORT_EXPORT_CANCELLED', 'REPORT_EXPORT', $exportId, ['reason' => $input['reason'], 'correlationId' => $correlationId], $now);
        });

        return (array) DB::table('report_exports')->where('id', $exportId)->first();
    }

    /** Module 7 Phase B: status lookup for a report export (does not return file bytes). */
    public function getExport(string $exportId, User $actor): array
    {
        return (array) $this->loadExportForActor($exportId, $actor);
    }

    /**
     * Module 7 Phase B AuthorizedDownload for exports: deliberately its
     * own method rather than a reuse of DocumentService::download(), since
     * a report export additionally gates on `report_exports.status
     * ='APPROVED'` and `report_exports.expires_at`, neither of which
     * DocumentService::download() knows about.
     *
     * @return array{bytes: string, contentType: string, fileName: string}
     */
    public function downloadExport(string $exportId, User $actor, string $correlationId): array
    {
        $row = $this->loadExportForActor($exportId, $actor);
        if ($row->status !== 'APPROVED') {
            throw new RepositoryConflictException('The report export is not approved for download.');
        }
        if (Carbon::parse($row->expires_at)->lessThanOrEqualTo(now())) {
            throw new PlatformResourceException('The report export has expired.', 410);
        }
        $document = DB::table('document_metadata')->where('id', $row->document_id)
            ->select('object_key', 'content_type', 'file_name', 'status')->first();
        if (! $document || $document->status !== 'ACTIVE') {
            throw new PlatformResourceException('The report export document is not available for download.', 404);
        }
        if (! Storage::disk('local')->exists($document->object_key)) {
            throw new PlatformResourceException('The report export object could not be located in storage.', 404);
        }
        $bytes = Storage::disk('local')->get($document->object_key);

        $now = now();
        AuditService::append($actor, 'REPORT_EXPORT_DOWNLOADED', 'REPORT_EXPORT', $exportId, ['documentId' => $row->document_id, 'correlationId' => $correlationId], $now);

        return ['bytes' => $bytes, 'contentType' => $document->content_type, 'fileName' => $document->file_name];
    }

    private function loadRunForExport(string $reportRunId): object
    {
        $run = DB::table('report_runs as r')->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
            ->where('r.id', $reportRunId)
            ->select('r.id', 'r.status', 'r.organisation_id', 'r.taxpayer_id', 'r.result_summary', 'r.requested_by', 'r.requested_at', 'd.code', 'd.name', 'd.audience', 'd.freshness_tier', 'd.classification')
            ->first();
        if (! $run) {
            throw new PlatformResourceException('Report run was not found.', 404);
        }

        return $run;
    }

    private function loadExportForActor(string $exportId, User $actor): object
    {
        $row = DB::table('report_exports as x')->join('report_runs as r', 'r.id', '=', 'x.report_run_id')
            ->where('x.id', $exportId)->select('x.*', 'r.taxpayer_id as run_taxpayer_id')->first();
        if (! $row) {
            throw new PlatformResourceException('Report export was not found.', 404);
        }
        if (! TenantScope::isNational($actor) && $row->requested_by !== $actor->id) {
            throw new AuthorizationException('You may only access a report export you requested.');
        }

        return $row;
    }

    /**
     * Module 7 Phase A: enforces the audience-tier guardrail from the
     * source's own reporting table before a report ever runs. Each tier's
     * guardrail is genuinely different in kind -- a national-scope check,
     * an executive-permission check, a case-authority check, or a live
     * delegation lookup -- so this is a real per-tier dispatch, not one
     * generic gate applied six times with a different label. `TAXPAYER`
     * and `OPEN_DATA` need no extra check here: `TAXPAYER` is already
     * correctly scoped by the existing OrganisationResolver-based own/all
     * split every report already does, and `OPEN_DATA`'s guardrail
     * (minimum-cell suppression) is a result-shaping concern handled where
     * that report computes its own summary, not an access concern.
     *
     * @return array{delegated_taxpayer_ids?: list<string>}
     */
    private function requireAudienceAccess(object $definition, User $actor): array
    {
        return match ($definition->audience) {
            'TAXPAYER', 'OPEN_DATA' => [],
            'NAMRA_OPERATIONS' => TenantScope::isNational($actor) ? [] : throw new AuthorizationException('This report is restricted to NamRA operations roles.'),
            'EXECUTIVE' => (TenantScope::isNational($actor) && $actor->hasAppPermission('reports:executive')) ? [] : throw new AuthorizationException('This report is restricted to executive roles.'),
            'AUDITOR_LEGAL' => ($actor->hasAppPermission('audit:read') || $actor->hasAppPermission('cases:manage')) ? [] : throw new AuthorizationException('This report requires audit case authority.'),
            'PRACTITIONER' => $this->requirePractitionerDelegations($actor),
            default => throw new PlatformResourceException('Unsupported report audience.', 500),
        };
    }

    /** @return array{delegated_taxpayer_ids: list<string>} */
    private function requirePractitionerDelegations(User $actor): array
    {
        $taxpayerIds = DB::table('delegations')->where('delegate_user_id', $actor->id)->where('status', 'ACTIVE')
            ->distinct()->pluck('taxpayer_id')->all();
        if (count($taxpayerIds) === 0) {
            throw new AuthorizationException('You have no active delegated taxpayers for this report.');
        }

        return ['delegated_taxpayer_ids' => $taxpayerIds];
    }

    /**
     * Module 7 Phase C: the per-code query logic, extracted out of
     * runInline() so the same deterministic computation can be re-run at
     * publish time (see publish() above) as the "reconciles to source
     * control totals" gate -- a genuinely fresh recomputation against live
     * source data, not a second copy-pasted query that could drift from
     * the first. Every seeded code has its own explicit branch; a
     * genuinely unimplemented definition fails closed (501) instead of
     * silently substituting an unrelated report.
     *
     * @param array{organisation_id: ?string, taxpayer_id: ?string, delegated_taxpayer_ids: ?list<string>, case_id: ?string} $scope
     * @return array<string, int|bool>
     */
    private function computeReportResult(string $code, array $scope): array
    {
        if ($code === 'VAT_POSITION') {
            $query = DB::table('vat_return_versions')->where('status', '<>', 'SUPERSEDED');
            if ($scope['organisation_id']) {
                $query->where('organisation_id', $scope['organisation_id']);
            }
            $row = $query->selectRaw('COUNT(*) as periods, COALESCE(SUM(net_payable_cents),0) as net_cents')->first();

            return ['periods' => (int) ($row->periods ?? 0), 'net_cents' => (int) ($row->net_cents ?? 0)];
        }
        if ($code === 'COMPLIANCE_CASELOAD') {
            $query = DB::table('audit_cases');
            if ($scope['organisation_id']) {
                $query->where('organisation_id', $scope['organisation_id']);
            }
            $row = $query->selectRaw("COUNT(*) as cases, SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) as open_cases")->first();

            return ['cases' => (int) ($row->cases ?? 0), 'open_cases' => (int) ($row->open_cases ?? 0)];
        }
        if ($code === 'SALES_VAT_SUMMARY') {
            $query = DB::table('invoices');
            if ($scope['taxpayer_id']) {
                $query->where('supplier_taxpayer_id', $scope['taxpayer_id']);
            }
            $row = $query->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total_cents),0) as total_cents, COALESCE(SUM(tax_cents),0) as tax_cents')->first();

            return ['invoices' => (int) ($row->invoices ?? 0), 'total_cents' => (int) ($row->total_cents ?? 0), 'tax_cents' => (int) ($row->tax_cents ?? 0)];
        }
        if ($code === 'PORTFOLIO_EXCEPTIONS') {
            $taxpayerIds = $scope['delegated_taxpayer_ids'] ?? [];
            if (count($taxpayerIds) === 0) {
                return ['exceptions' => 0, 'open_exceptions' => 0];
            }
            $row = DB::table('reconciliation_exceptions')->whereIn('taxpayer_id', $taxpayerIds)
                ->selectRaw("COUNT(*) as exceptions, SUM(CASE WHEN status='OPEN' THEN 1 ELSE 0 END) as open_exceptions")->first();

            return ['exceptions' => (int) ($row->exceptions ?? 0), 'open_exceptions' => (int) ($row->open_exceptions ?? 0)];
        }
        if ($code === 'REVENUE_COMPLIANCE_TRENDS') {
            $invoiceRow = DB::table('invoices')->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total_cents),0) as total_cents')->first();
            $caseRow = DB::table('audit_cases')->selectRaw("COUNT(*) as cases, SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) as open_cases")->first();

            return [
                'invoices' => (int) ($invoiceRow->invoices ?? 0), 'total_cents' => (int) ($invoiceRow->total_cents ?? 0),
                'cases' => (int) ($caseRow->cases ?? 0), 'open_cases' => (int) ($caseRow->open_cases ?? 0),
            ];
        }
        if ($code === 'CASE_EVIDENCE_SUMMARY') {
            $caseId = $scope['case_id'] ?? '';
            $evidenceRow = DB::table('audit_evidence')->where('audit_case_id', $caseId)
                ->selectRaw("COUNT(*) as evidence_items, SUM(CASE WHEN status='PRESERVED' THEN 1 ELSE 0 END) as preserved_items")->first();
            $custodyRow = DB::table('audit_evidence_custody_events as cce')->join('audit_evidence as ae', 'ae.id', '=', 'cce.audit_evidence_id')
                ->where('ae.audit_case_id', $caseId)->selectRaw('COUNT(*) as custody_events')->first();

            return [
                'evidence_items' => (int) ($evidenceRow->evidence_items ?? 0), 'preserved_items' => (int) ($evidenceRow->preserved_items ?? 0),
                'custody_events' => (int) ($custodyRow->custody_events ?? 0),
            ];
        }
        if ($code === 'NATIONAL_VAT_AGGREGATE') {
            $row = DB::table('invoices')->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total_cents),0) as total_cents')->first();
            $invoiceCount = (int) ($row->invoices ?? 0);
            $minCellSuppressionThreshold = PlatformConfigReader::int('reports.min_cell_suppression_threshold', self::MIN_CELL_SUPPRESSION_THRESHOLD_DEFAULT);
            $suppressed = $invoiceCount < $minCellSuppressionThreshold;

            return $suppressed
                ? ['invoices' => 0, 'total_cents' => 0, 'suppressed' => true]
                : ['invoices' => $invoiceCount, 'total_cents' => (int) ($row->total_cents ?? 0), 'suppressed' => false];
        }

        throw new PlatformResourceException('This report definition has no runnable implementation.', 501);
    }

    /**
     * Module 7 Phase C: the shared as-of-time/source-freshness/filters/
     * currency-basis/rule-version envelope, wrapping every report
     * response. `currency_basis` is a real constant, not a stub -- every
     * monetary figure in this schema is denominated in NAD only, a
     * genuinely single-currency pilot today. `rule_version` reuses
     * `report_definitions.query_version` -- the version of this report's
     * own computation logic -- rather than the VAT rule version, since
     * most of these reports have no VAT rule dependency to version at all.
     */
    private function buildEnvelope(object $definition, array $filters, Carbon $asOf): array
    {
        return [
            'as_of' => $asOf->toISOString(), 'audience' => $definition->audience, 'freshness_tier' => $definition->freshness_tier,
            'guardrail' => $definition->guardrail, 'filters' => $filters, 'currency_basis' => self::CURRENCY_BASIS,
            'rule_version' => $definition->query_version,
        ];
    }

    private function buildExportContent(object $run, string $watermark): string
    {
        $summary = json_decode($run->result_summary, true) ?? [];
        $lines = [
            "# code:{$run->code}", "# name:{$run->name}", "# audience:{$run->audience}",
            "# freshness_tier:{$run->freshness_tier}", '# as_of:'.($run->requested_at ?? ''), "# watermark:{$watermark}",
            'field,value',
        ];
        foreach ($summary as $key => $value) {
            $lines[] = "{$key},".$this->csvValue($value);
        }

        return implode("\n", $lines)."\n";
    }

    private function csvValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}

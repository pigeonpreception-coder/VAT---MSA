<?php

namespace App\Services\VatLifecycle;

use App\Domain\VatLifecycle\VatLifecycleValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\VatLifecycleResourceException;
use App\Integrations\Itas\ItasIdentityPort;
use App\Integrations\Itas\ItasIntegrationUnavailableException;
use App\Models\ApprovalTask;
use App\Models\TaxRuleSet;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatAdjustment;
use App\Models\VatPeriod;
use App\Models\VatReturnBox;
use App\Models\VatReturnSubmission;
use App\Models\VatReturnVersion;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/vat-lifecycle-repository.ts -- the VAT-return-
 * generation prerequisite Phase 9 deferred and Phase 11's refund slice was
 * blocked on (see docs/MIGRATION_MATRIX.md's Phase 9/11 rows). Covers
 * getVatLifecycleSnapshot/getVatReturnDetail/createVatAdjustment/
 * generateVatReturn/requestReturnApproval/decideVatApproval/
 * submitVatReturn in full.
 *
 * Two things this port's source has NO application write path for at all
 * (confirmed by grepping every .ts file under lib/ before writing this
 * class): opening a `vat_periods` row and provisioning a `tax_rule_sets`
 * row. Both are pure out-of-band/seed data in the original -- a still-
 * undocumented ops process outside this codebase's own scope, not a
 * scoping choice made here. This service therefore only ever reads and
 * transitions periods/rule sets, never creates either; DemoSeeder
 * provisions the same fixture rows the source's own db/runtime.ts seeds.
 *
 * `evidence_document_id` on a VAT adjustment is deliberately rejected
 * outright (rather than silently accepted with no real document_metadata
 * table behind it) -- the same deferral pattern already used for
 * REFUND_CLAIM-referenced notices in CommunicationService.
 */
class VatLifecycleService
{
    public function __construct(private readonly ItasIdentityPort $itas) {}

    /** @return array<string, mixed> */
    public function snapshot(User $actor): array
    {
        $scoped = ! TenantScope::isNational($actor);
        $periods = VatPeriod::with('taxpayer')->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('period_end')->get();

        $periodRows = $periods->map(function (VatPeriod $period) {
            $latest = VatReturnVersion::where('vat_period_id', $period->id)->orderByDesc('version_number')->first();

            return [
                'id' => $period->id, 'organisation_id' => $period->organisation_id, 'taxpayer_id' => $period->taxpayer_id,
                'legal_name' => $period->taxpayer?->legal_name, 'vat_number' => $period->taxpayer?->vat_number,
                'period_code' => $period->period_code, 'period_start' => optional($period->period_start)->toDateString(),
                'period_end' => optional($period->period_end)->toDateString(), 'due_date' => optional($period->due_date)->toDateString(),
                'status' => $period->status, 'lock_version' => (int) $period->lock_version,
                'matched_count' => DB::table('reconciliation_matches')->where('vat_period_id', $period->id)->where('status', 'MATCHED')->count(),
                'unmatched_count' => DB::table('reconciliation_matches')->where('vat_period_id', $period->id)->where('status', '<>', 'MATCHED')->count(),
                'pending_adjustments' => VatAdjustment::where('vat_period_id', $period->id)->where('status', 'PENDING_APPROVAL')->count(),
                'latest_return_id' => $latest?->id, 'latest_version' => $latest?->version_number,
                'return_status' => $latest?->status, 'output_tax_cents' => $latest?->output_tax_cents,
                'input_tax_cents' => $latest?->input_tax_cents, 'net_payable_cents' => $latest?->net_payable_cents,
            ];
        })->values()->all();

        $approvals = ApprovalTask::with('taxpayer')->where('domain', 'VAT_RETURN')
            ->when($scoped, fn ($q) => $q->where('taxpayer_id', $actor->taxpayer_id))
            ->orderByDesc('requested_at')->limit(100)->get()
            ->map(fn (ApprovalTask $task) => $this->presentApprovalTask($task, $scoped ? null : $task->taxpayer?->legal_name))->all();

        $submissions = VatReturnSubmission::with(['version.period'])
            ->when($scoped, fn ($q) => $q->whereHas('version', fn ($v) => $v->where('taxpayer_id', $actor->taxpayer_id)))
            ->orderByDesc('requested_at')->limit(100)->get()
            ->map(fn (VatReturnSubmission $submission) => $this->presentSubmission($submission))->all();

        $rules = TaxRuleSet::orderByDesc('effective_from')->get()
            ->map(fn (TaxRuleSet $rule) => $this->presentRule($rule))->all();

        $reconciliation = DB::table('reconciliation_matches as m')
            ->join('invoices as i', 'i.id', '=', 'm.invoice_id')
            ->when($scoped, fn ($q) => $q->where('m.taxpayer_id', $actor->taxpayer_id))
            ->when(! $scoped, fn ($q) => $q->join('taxpayers as t', 't.id', '=', 'm.taxpayer_id'))
            ->select(array_filter([
                'm.*', 'i.invoice_number', 'i.status as invoice_status', $scoped ? null : 't.legal_name',
            ]))
            ->orderByDesc('m.created_at')->limit(100)->get();

        $provider = $this->itasStatus();

        return [
            'periods' => $periodRows, 'approvals' => $approvals, 'submissions' => $submissions,
            'rules' => $rules, 'reconciliation' => $reconciliation->all(), 'provider' => $provider,
        ];
    }

    /** @return array<string, mixed> */
    public function returnDetail(string $versionId, User $actor): array
    {
        $version = $this->getVersionForActor($versionId, $actor);
        $boxes = VatReturnBox::where('vat_return_version_id', $versionId)->orderBy('box_code')->get()
            ->map(fn (VatReturnBox $box) => $this->presentBox($box))->all();
        $adjustments = VatAdjustment::where('vat_period_id', $version->vat_period_id)->orderBy('created_at')->get()
            ->map(fn (VatAdjustment $adjustment) => $this->presentAdjustment($adjustment))->all();
        $approvals = ApprovalTask::where('resource_type', 'VAT_RETURN_VERSION')->where('resource_id', $versionId)
            ->orderBy('requested_at')->get()->map(fn (ApprovalTask $task) => $this->presentApprovalTask($task, null))->all();
        $submissions = VatReturnSubmission::where('vat_return_version_id', $versionId)->orderBy('requested_at')->get()
            ->map(fn (VatReturnSubmission $submission) => $this->presentSubmission($submission))->all();

        return [
            'version' => $this->present($version), 'boxes' => $boxes, 'adjustments' => $adjustments,
            'approvals' => $approvals, 'submissions' => $submissions,
        ];
    }

    /** @return array<string, mixed> */
    public function createAdjustment(string $periodId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $adjustment = VatLifecycleValidator::adjustment($payload);
        $period = $this->getPeriodForActor($periodId, $actor);
        if ($period->status !== 'OPEN') {
            throw new RepositoryConflictException("Adjustments require an open VAT period; current status is {$period->status}.");
        }
        if ($adjustment['evidence_document_id'] !== null) {
            throw new VatLifecycleResourceException('Evidence documents are not yet supported by this migration -- document_metadata has not been ported. Submit the adjustment without evidence_document_id.');
        }
        $requestHash = CommandLedger::requestHash(['period_id' => $period->id, 'adjustment' => $adjustment]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_VAT_ADJUSTMENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentAdjustment(VatAdjustment::findOrFail($prior));
        }

        $id = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($adjustment, $period, $actor, $id, $taskId, $now, $idempotencyKey, $requestHash, $correlationId) {
            VatAdjustment::create([
                'id' => $id, 'vat_period_id' => $period->id, 'organisation_id' => $period->organisation_id, 'taxpayer_id' => $period->taxpayer_id,
                'adjustment_type' => $adjustment['adjustment_type'], 'direction' => $adjustment['direction'], 'amount_cents' => $adjustment['amount_cents'],
                'reason_code' => $adjustment['reason_code'], 'explanation' => $adjustment['explanation'], 'evidence_document_id' => null,
                'status' => 'PENDING_APPROVAL', 'created_by' => $actor->id, 'approved_by' => null, 'created_at' => $now, 'approved_at' => null,
            ]);
            ApprovalTask::create([
                'id' => $taskId, 'organisation_id' => $period->organisation_id, 'taxpayer_id' => $period->taxpayer_id, 'domain' => 'VAT_RETURN',
                'resource_type' => 'VAT_ADJUSTMENT', 'resource_id' => $id, 'requested_action' => 'APPROVE_ADJUSTMENT', 'risk_tier' => 'HIGH',
                'status' => 'PENDING', 'requested_by' => $actor->id, 'assigned_role' => 'TAXPAYER_OWNER', 'decided_by' => null,
                'requested_at' => $now, 'decided_at' => null, 'decision_comment' => null,
            ]);
            CommandLedger::record($actor->id, 'CREATE_VAT_ADJUSTMENT', $idempotencyKey, $requestHash, 'VAT_ADJUSTMENT', $id, $now);
            CommandLedger::outbox('VAT_ADJUSTMENT', $id, 'VatAdjustmentApprovalRequested', $period->taxpayer_id, ['adjustment_id' => $id, 'period_id' => $period->id, 'task_id' => $taskId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'VAT_ADJUSTMENT_SUBMITTED', 'VAT_ADJUSTMENT', $id, ['periodId' => $period->id, 'taskId' => $taskId, 'amountCents' => $adjustment['amount_cents'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentAdjustment(VatAdjustment::findOrFail($id));
    }

    /** @return array<string, mixed> */
    public function generateReturn(string $periodId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $period = $this->getPeriodForActor($periodId, $actor);
        if ($period->status !== 'OPEN') {
            throw new RepositoryConflictException("Return generation requires an open VAT period; current status is {$period->status}.");
        }
        $blocking = VatReturnVersion::where('vat_period_id', $period->id)
            ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'AWAITING_PROVIDER', 'FILED'])->first();
        if ($blocking) {
            throw new RepositoryConflictException("Return version {$blocking->id} is already in controlled status {$blocking->status}.");
        }
        $rule = TaxRuleSet::where('jurisdiction', 'NA')->whereIn('status', ['PILOT_CONTROLLED', 'AUTHORITY_APPROVED'])
            ->where('effective_from', '<=', $period->period_end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period->period_start->toDateString()))
            ->orderByDesc('effective_from')->first();
        if (! $rule) {
            throw new VatLifecycleResourceException('No controlled tax rule set covers this VAT period.');
        }

        $output = DB::table('ledger_entries as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('certificates as c', fn ($j) => $j->on('c.invoice_id', '=', 'i.id')->where('c.status', 'VALID'))
            ->where('l.taxpayer_id', $period->taxpayer_id)->where('l.period', $period->period_code)
            ->where('l.entry_type', 'OUTPUT_VAT')->whereIn('i.status', ['CERTIFIED', 'MATCHED', 'EXCEPTION'])
            ->orderBy('l.id')
            ->selectRaw("l.id, CASE WHEN l.direction='CREDIT' THEN l.amount_cents ELSE -l.amount_cents END as amount_cents")
            ->get();
        $input = DB::table('ledger_entries as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('certificates as c', fn ($j) => $j->on('c.invoice_id', '=', 'i.id')->where('c.status', 'VALID'))
            ->where('l.taxpayer_id', $period->taxpayer_id)->where('l.period', $period->period_code)
            ->where('l.entry_type', 'INPUT_VAT')->where('i.status', 'MATCHED')
            ->orderBy('l.id')
            ->selectRaw("l.id, CASE WHEN l.direction='DEBIT' THEN l.amount_cents ELSE -l.amount_cents END as amount_cents")
            ->get();
        $adjustments = VatAdjustment::where('vat_period_id', $period->id)->where('status', 'APPROVED')->orderBy('id')
            ->get(['id', 'adjustment_type', 'direction', 'amount_cents']);
        $priorVersion = VatReturnVersion::where('vat_period_id', $period->id)->orderByDesc('version_number')->first();

        $position = VatLifecycleValidator::calculateReturnPosition(
            $output->map(fn ($e) => ['id' => $e->id, 'amount_cents' => (int) $e->amount_cents])->all(),
            $input->map(fn ($e) => ['id' => $e->id, 'amount_cents' => (int) $e->amount_cents])->all(),
            $adjustments->map(fn ($a) => ['id' => $a->id, 'adjustment_type' => $a->adjustment_type, 'direction' => $a->direction, 'amount_cents' => (int) $a->amount_cents])->all(),
        );
        $snapshot = [
            'period' => $period->period_code, 'tax_rule' => $rule->version,
            'output' => $output->map(fn ($e) => ['id' => $e->id, 'amount_cents' => (int) $e->amount_cents])->all(),
            'input' => $input->map(fn ($e) => ['id' => $e->id, 'amount_cents' => (int) $e->amount_cents])->all(),
            'adjustments' => $adjustments->map(fn ($a) => ['id' => $a->id, 'amount_cents' => (int) $a->amount_cents])->all(),
            'position' => $position,
        ];
        $snapshotHash = hash('sha256', AuditService::canonicalJson($snapshot));
        $requestHash = CommandLedger::requestHash(['period_id' => $period->id, 'snapshot_hash' => $snapshotHash]);
        $prior = CommandLedger::prior($actor->id, 'GENERATE_VAT_RETURN', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(VatReturnVersion::findOrFail($prior));
        }

        $id = (string) Str::uuid();
        $versionNumber = ($priorVersion?->version_number ?? 0) + 1;
        $now = now();
        DB::transaction(function () use ($period, $rule, $priorVersion, $position, $snapshotHash, $actor, $id, $versionNumber, $now, $idempotencyKey, $requestHash, $correlationId, $output, $input, $adjustments) {
            if ($priorVersion) {
                VatReturnVersion::where('id', $priorVersion->id)->whereIn('status', ['DRAFT', 'REJECTED'])
                    ->update(['status' => 'SUPERSEDED', 'superseded_at' => $now]);
            }
            VatReturnVersion::create([
                'id' => $id, 'vat_period_id' => $period->id, 'organisation_id' => $period->organisation_id, 'taxpayer_id' => $period->taxpayer_id,
                'version_number' => $versionNumber, 'parent_version_id' => $priorVersion?->id, 'tax_rule_set_id' => $rule->id,
                'output_tax_cents' => $position['outputTaxCents'], 'input_tax_cents' => $position['inputTaxCents'],
                'adjustment_cents' => $position['adjustmentCents'], 'net_payable_cents' => $position['netPayableCents'],
                'status' => 'DRAFT', 'ledger_snapshot_hash' => $snapshotHash, 'generated_by' => $actor->id, 'generated_at' => $now,
                'approved_by' => null, 'approved_at' => null, 'superseded_at' => null,
            ]);
            $boxes = [
                ['code' => 'BOX_OUTPUT', 'label' => 'Output VAT', 'amount' => $position['outputTaxCents'], 'count' => $position['outputSourceCount'], 'trace' => ['entry_type' => 'OUTPUT_VAT', 'status' => ['CERTIFIED', 'MATCHED', 'EXCEPTION']]],
                ['code' => 'BOX_INPUT', 'label' => 'Eligible input VAT', 'amount' => $position['inputTaxCents'], 'count' => $position['inputSourceCount'], 'trace' => ['entry_type' => 'INPUT_VAT', 'invoice_status' => 'MATCHED']],
                ['code' => 'BOX_ADJUST', 'label' => 'Approved net adjustments', 'amount' => $position['adjustmentCents'], 'count' => $position['adjustmentSourceCount'], 'trace' => ['adjustment_status' => 'APPROVED']],
                ['code' => 'BOX_NET', 'label' => 'Net VAT payable or refundable', 'amount' => $position['netPayableCents'], 'count' => $output->count() + $input->count() + $adjustments->count(), 'trace' => ['formula' => 'OUTPUT - INPUT + NET_ADJUSTMENTS']],
            ];
            foreach ($boxes as $box) {
                VatReturnBox::create([
                    'id' => (string) Str::uuid(), 'vat_return_version_id' => $id, 'box_code' => $box['code'], 'label' => $box['label'],
                    'amount_cents' => $box['amount'], 'source_count' => $box['count'], 'calculation_trace' => AuditService::canonicalJson($box['trace']),
                ]);
            }
            CommandLedger::record($actor->id, 'GENERATE_VAT_RETURN', $idempotencyKey, $requestHash, 'VAT_RETURN_VERSION', $id, $now);
            CommandLedger::outbox('VAT_RETURN_VERSION', $id, 'VatReturnDrafted', $period->taxpayer_id, ['return_version_id' => $id, 'period_id' => $period->id, 'version' => $versionNumber, 'snapshot_hash' => $snapshotHash, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'VAT_RETURN_GENERATED', 'VAT_RETURN_VERSION', $id, ['periodId' => $period->id, 'versionNumber' => $versionNumber, 'snapshotHash' => $snapshotHash, 'correlationId' => $correlationId], $now);
        });

        return $this->present(VatReturnVersion::findOrFail($id));
    }

    /** @return array<string, mixed> */
    public function requestReturnApproval(string $versionId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $version = $this->getVersionForActor($versionId, $actor);
        if ($version->status !== 'DRAFT') {
            throw new RepositoryConflictException("Only a draft return can enter approval; current status is {$version->status}.");
        }
        $requestHash = CommandLedger::requestHash(['version_id' => $version->id, 'snapshot_hash' => $version->ledger_snapshot_hash, 'action' => 'REQUEST_APPROVAL']);
        $prior = CommandLedger::prior($actor->id, 'REQUEST_RETURN_APPROVAL', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentApprovalTask(ApprovalTask::findOrFail($prior), null);
        }

        $taskId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($version, $actor, $taskId, $now, $idempotencyKey, $requestHash, $correlationId) {
            VatReturnVersion::where('id', $version->id)->where('status', 'DRAFT')->update(['status' => 'PENDING_APPROVAL']);
            ApprovalTask::create([
                'id' => $taskId, 'organisation_id' => $version->organisation_id, 'taxpayer_id' => $version->taxpayer_id, 'domain' => 'VAT_RETURN',
                'resource_type' => 'VAT_RETURN_VERSION', 'resource_id' => $version->id, 'requested_action' => 'APPROVE_RETURN', 'risk_tier' => 'CRITICAL',
                'status' => 'PENDING', 'requested_by' => $actor->id, 'assigned_role' => 'TAXPAYER_OWNER', 'decided_by' => null,
                'requested_at' => $now, 'decided_at' => null, 'decision_comment' => null,
            ]);
            CommandLedger::record($actor->id, 'REQUEST_RETURN_APPROVAL', $idempotencyKey, $requestHash, 'APPROVAL_TASK', $taskId, $now);
            CommandLedger::outbox('VAT_RETURN_VERSION', $version->id, 'VatReturnApprovalRequested', $version->taxpayer_id, ['return_version_id' => $version->id, 'task_id' => $taskId, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'VAT_RETURN_APPROVAL_REQUESTED', 'VAT_RETURN_VERSION', $version->id, ['taskId' => $taskId, 'correlationId' => $correlationId], $now);
        });

        return $this->presentApprovalTask(ApprovalTask::findOrFail($taskId), null);
    }

    /** @return array<string, mixed> */
    public function decideApproval(string $taskId, array $decisionInput, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $decision = mb_strtoupper(is_string($decisionInput['decision'] ?? null) ? trim($decisionInput['decision']) : '');
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new VatLifecycleResourceException('Decision must be APPROVE or REJECT.');
        }
        $comment = VatLifecycleValidator::decisionComment($decisionInput['comment'] ?? null);
        $task = ApprovalTask::where('id', $taskId)->where('domain', 'VAT_RETURN')->first();
        if (! $task) {
            throw new VatLifecycleResourceException('Approval task was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $task->taxpayer_id);
        if ($task->status !== 'PENDING') {
            throw new RepositoryConflictException("Approval task is already {$task->status}.");
        }
        if ($task->requested_by === $actor->id) {
            throw new AuthorizationException('Maker-checker separation prevents approving or rejecting your own request.');
        }
        $requestHash = CommandLedger::requestHash(['task_id' => $task->id, 'decision' => $decision, 'comment' => $comment]);
        $prior = CommandLedger::prior($actor->id, 'DECIDE_VAT_APPROVAL', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentApprovalTask(ApprovalTask::findOrFail($prior), null);
        }

        $now = now();
        $nextTaskStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        DB::transaction(function () use ($task, $decision, $nextTaskStatus, $comment, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            ApprovalTask::where('id', $task->id)->where('status', 'PENDING')
                ->update(['status' => $nextTaskStatus, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_comment' => $comment]);

            if ($task->resource_type === 'VAT_RETURN_VERSION') {
                $version = $this->getVersionForActor($task->resource_id, $actor);
                if ($version->status !== 'PENDING_APPROVAL') {
                    throw new RepositoryConflictException("Return approval state is {$version->status}, not PENDING_APPROVAL.");
                }
                VatReturnVersion::where('id', $version->id)->where('status', 'PENDING_APPROVAL')->update([
                    'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                    'approved_by' => $decision === 'APPROVE' ? $actor->id : null,
                    'approved_at' => $decision === 'APPROVE' ? $now : null,
                ]);
                VatPeriod::where('id', $version->vat_period_id)->update([
                    'status' => $decision === 'APPROVE' ? 'LOCKED' : 'OPEN', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $now,
                ]);
            } elseif ($task->resource_type === 'VAT_ADJUSTMENT') {
                VatAdjustment::where('id', $task->resource_id)->where('status', 'PENDING_APPROVAL')->update([
                    'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                    'approved_by' => $decision === 'APPROVE' ? $actor->id : null,
                    'approved_at' => $decision === 'APPROVE' ? $now : null,
                ]);
            } else {
                throw new VatLifecycleResourceException('Approval task resource type is unsupported.');
            }

            CommandLedger::record($actor->id, 'DECIDE_VAT_APPROVAL', $idempotencyKey, $requestHash, 'APPROVAL_TASK', $task->id, $now);
            CommandLedger::outbox($task->resource_type, $task->resource_id, $decision === 'APPROVE' ? 'VatControlApproved' : 'VatControlRejected', $task->taxpayer_id, ['task_id' => $task->id, 'resource_id' => $task->resource_id, 'decision' => $decision, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, "VAT_{$task->resource_type}_{$nextTaskStatus}", $task->resource_type, $task->resource_id, ['taskId' => $task->id, 'decision' => $decision, 'comment' => $comment, 'correlationId' => $correlationId], $now);
        });

        return $this->presentApprovalTask(ApprovalTask::findOrFail($task->id), null);
    }

    /**
     * Ported from submitVatReturn -- genuinely calls
     * ItasIdentityPort::submitVatReturn once the local AUTHORITY_APPROVED
     * gate passes (never attempted otherwise), and re-attempts a still-open
     * BLOCKED_CONFIGURATION submission by UPDATE-in-place rather than a
     * fresh INSERT, matching the source's own fix for the
     * UNIQUE(provider, request_reference) retry race documented there.
     *
     * @return array<string, mixed>
     */
    public function submitReturn(string $versionId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $version = $this->getVersionForActor($versionId, $actor);
        if ($version->status !== 'APPROVED') {
            throw new VatLifecycleResourceException("Only an approved return can be submitted; current status is {$version->status}.", 409);
        }
        $boxes = VatReturnBox::where('vat_return_version_id', $version->id)->orderBy('box_code')->get(['box_code', 'amount_cents']);
        $rule = TaxRuleSet::find($version->tax_rule_set_id);
        if (! $rule) {
            throw new VatLifecycleResourceException("The return's tax rule set is unavailable.");
        }
        $period = VatPeriod::findOrFail($version->vat_period_id);
        $taxpayer = $version->taxpayer_id ? Taxpayer::find($version->taxpayer_id) : null;
        $requestReference = "vat-return:{$version->id}:v{$version->version_number}";
        $requestHash = CommandLedger::requestHash([
            'requestReference' => $requestReference, 'vatNumber' => $taxpayer?->vat_number, 'period' => $period->period_code,
            'version' => $version->version_number, 'snapshot' => $version->ledger_snapshot_hash,
            'boxes' => $boxes->map(fn ($b) => ['box_code' => $b->box_code, 'amount_cents' => (int) $b->amount_cents])->all(),
        ]);
        $prior = CommandLedger::prior($actor->id, 'SUBMIT_VAT_RETURN', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentSubmission(VatReturnSubmission::findOrFail($prior));
        }

        $priorAttempt = VatReturnSubmission::where('provider', 'ITAS')->where('request_reference', $requestReference)->first();
        if ($priorAttempt?->status === 'ACKNOWLEDGED') {
            throw new RepositoryConflictException('This return has already been submitted and acknowledged by ITAS.');
        }

        $id = $priorAttempt?->id ?? (string) Str::uuid();
        $attemptCount = ($priorAttempt?->attempt_count ?? 0) + 1;
        $now = now();
        $status = null;
        $providerReference = null;
        $responseHash = null;
        $submittedAt = null;
        $acknowledgedAt = null;
        $blocker = null;
        $eventType = null;

        if ($rule->status !== 'AUTHORITY_APPROVED') {
            $status = 'BLOCKED_CONFIGURATION';
            $blocker = 'Tax rule set lacks authority approval.';
            $eventType = 'VatReturnSubmissionBlocked';
        } else {
            try {
                $result = $this->itas->submitVatReturn([
                    'request_reference' => $requestReference, 'taxpayer_vat_number' => $taxpayer?->vat_number,
                    'period_code' => $period->period_code, 'return_version' => $version->version_number,
                    'payload_hash' => $version->ledger_snapshot_hash,
                    'boxes' => $boxes->map(fn ($b) => ['code' => $b->box_code, 'amount_cents' => (int) $b->amount_cents])->all(),
                    'correlation_id' => $correlationId,
                ]);
                $status = $result['status'] === 'ACCEPTED' ? 'ACKNOWLEDGED' : 'REJECTED_BY_PROVIDER';
                $providerReference = $result['provider_reference'];
                $responseHash = $result['response_hash'];
                $submittedAt = $result['submitted_at'];
                $acknowledgedAt = $result['status'] === 'ACCEPTED' ? $result['submitted_at'] : null;
                if ($result['status'] === 'REJECTED') {
                    $blocker = 'ITAS rejected the submission.';
                }
                $eventType = $result['status'] === 'ACCEPTED' ? 'VATReturnSubmitted' : 'VatReturnSubmissionBlocked';
            } catch (ItasIntegrationUnavailableException) {
                $status = 'BLOCKED_CONFIGURATION';
                $blocker = 'ITAS technical contract and credentials are not configured.';
                $eventType = 'VatReturnSubmissionBlocked';
            }
        }

        DB::transaction(function () use ($priorAttempt, $id, $version, $requestReference, $status, $requestHash, $providerReference, $responseHash, $attemptCount, $actor, $now, $submittedAt, $acknowledgedAt, $blocker, $idempotencyKey, $correlationId, $eventType) {
            if ($priorAttempt) {
                VatReturnSubmission::where('id', $id)->update([
                    'status' => $status, 'request_hash' => $requestHash, 'provider_reference' => $providerReference,
                    'response_hash' => $responseHash, 'attempt_count' => $attemptCount, 'requested_by' => $actor->id,
                    'requested_at' => $now, 'submitted_at' => $submittedAt, 'acknowledged_at' => $acknowledgedAt, 'last_error' => $blocker,
                ]);
            } else {
                VatReturnSubmission::create([
                    'id' => $id, 'vat_return_version_id' => $version->id, 'provider' => 'ITAS', 'request_reference' => $requestReference,
                    'status' => $status, 'request_hash' => $requestHash, 'provider_reference' => $providerReference, 'response_hash' => $responseHash,
                    'attempt_count' => $attemptCount, 'requested_by' => $actor->id, 'requested_at' => $now,
                    'submitted_at' => $submittedAt, 'acknowledged_at' => $acknowledgedAt, 'last_error' => $blocker,
                ]);
            }
            CommandLedger::record($actor->id, 'SUBMIT_VAT_RETURN', $idempotencyKey, $requestHash, 'VAT_RETURN_SUBMISSION', $id, $now);
            // event-catalog.csv's VATReturnSubmitted fires only on a genuine ITAS ACCEPTED outcome -- every other
            // path (local rule-authority gate, ITAS unavailable, provider REJECTED) is honestly VatReturnSubmissionBlocked.
            CommandLedger::outbox('VAT_RETURN_VERSION', $version->id, $eventType, $version->taxpayer_id, ['vatReturnId' => $version->id, 'submissionId' => $id, 'payloadHash' => $version->ledger_snapshot_hash, 'submittedAt' => $submittedAt, 'status' => $status, 'blocker' => $blocker, 'correlationId' => $correlationId], $now);
            AuditService::append($actor, $status === 'ACKNOWLEDGED' ? 'VAT_RETURN_ACKNOWLEDGED' : 'VAT_RETURN_SUBMISSION_BLOCKED', 'VAT_RETURN_VERSION', $version->id, ['submissionId' => $id, 'status' => $status, 'blocker' => $blocker, 'correlationId' => $correlationId], $now);
        });

        return $this->presentSubmission(VatReturnSubmission::findOrFail($id));
    }

    private function getPeriodForActor(string $periodId, User $actor): VatPeriod
    {
        $period = VatPeriod::find($periodId);
        if (! $period) {
            throw new VatLifecycleResourceException('VAT period was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $period->taxpayer_id);

        return $period;
    }

    private function getVersionForActor(string $versionId, User $actor): VatReturnVersion
    {
        $version = VatReturnVersion::find($versionId);
        if (! $version) {
            throw new VatLifecycleResourceException('VAT return version was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $version->taxpayer_id);

        return $version;
    }

    /** @return array{provider: string, configured: bool, state: string, capabilities: list<string>} */
    private function itasStatus(): array
    {
        return $this->itas->status();
    }

    /** @return array<string, mixed> */
    private function present(VatReturnVersion $version): array
    {
        return [
            'id' => $version->id, 'vat_period_id' => $version->vat_period_id, 'organisation_id' => $version->organisation_id,
            'taxpayer_id' => $version->taxpayer_id, 'version_number' => $version->version_number, 'parent_version_id' => $version->parent_version_id,
            'tax_rule_set_id' => $version->tax_rule_set_id, 'output_tax_cents' => (int) $version->output_tax_cents,
            'input_tax_cents' => (int) $version->input_tax_cents, 'adjustment_cents' => (int) $version->adjustment_cents,
            'net_payable_cents' => (int) $version->net_payable_cents, 'status' => $version->status,
            'ledger_snapshot_hash' => $version->ledger_snapshot_hash, 'generated_by' => $version->generated_by,
            'generated_at' => optional($version->generated_at)->toISOString(), 'approved_by' => $version->approved_by,
            'approved_at' => optional($version->approved_at)->toISOString(), 'superseded_at' => optional($version->superseded_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAdjustment(VatAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id, 'vat_period_id' => $adjustment->vat_period_id, 'organisation_id' => $adjustment->organisation_id,
            'taxpayer_id' => $adjustment->taxpayer_id, 'adjustment_type' => $adjustment->adjustment_type, 'direction' => $adjustment->direction,
            'amount_cents' => (int) $adjustment->amount_cents, 'reason_code' => $adjustment->reason_code, 'explanation' => $adjustment->explanation,
            'evidence_document_id' => $adjustment->evidence_document_id, 'status' => $adjustment->status, 'created_by' => $adjustment->created_by,
            'approved_by' => $adjustment->approved_by, 'created_at' => optional($adjustment->created_at)->toISOString(),
            'approved_at' => optional($adjustment->approved_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentApprovalTask(ApprovalTask $task, ?string $legalName): array
    {
        return [
            'id' => $task->id, 'organisation_id' => $task->organisation_id, 'taxpayer_id' => $task->taxpayer_id,
            'legal_name' => $legalName, 'domain' => $task->domain, 'resource_type' => $task->resource_type, 'resource_id' => $task->resource_id,
            'requested_action' => $task->requested_action, 'risk_tier' => $task->risk_tier, 'status' => $task->status,
            'requested_by' => $task->requested_by, 'assigned_role' => $task->assigned_role, 'decided_by' => $task->decided_by,
            'requested_at' => optional($task->requested_at)->toISOString(), 'decided_at' => optional($task->decided_at)->toISOString(),
            'decision_comment' => $task->decision_comment,
        ];
    }

    /** @return array<string, mixed> */
    private function presentSubmission(VatReturnSubmission $submission): array
    {
        $version = $submission->relationLoaded('version') ? $submission->version : $submission->version()->with('period')->first();

        return [
            'id' => $submission->id, 'vat_return_version_id' => $submission->vat_return_version_id, 'provider' => $submission->provider,
            'request_reference' => $submission->request_reference, 'status' => $submission->status, 'provider_reference' => $submission->provider_reference,
            'attempt_count' => (int) $submission->attempt_count, 'requested_by' => $submission->requested_by,
            'requested_at' => optional($submission->requested_at)->toISOString(), 'submitted_at' => optional($submission->submitted_at)->toISOString(),
            'acknowledged_at' => optional($submission->acknowledged_at)->toISOString(), 'last_error' => $submission->last_error,
            'version_number' => $version?->version_number, 'period_code' => $version?->period?->period_code,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRule(TaxRuleSet $rule): array
    {
        return [
            'id' => $rule->id, 'jurisdiction' => $rule->jurisdiction, 'version' => $rule->version,
            'effective_from' => optional($rule->effective_from)->toDateString(), 'effective_to' => optional($rule->effective_to)->toDateString(),
            'standard_rate_bps' => (int) $rule->standard_rate_bps, 'legal_authority_reference' => $rule->legal_authority_reference,
            'status' => $rule->status, 'approved_by' => $rule->approved_by, 'approved_at' => optional($rule->approved_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentBox(VatReturnBox $box): array
    {
        return [
            'id' => $box->id, 'vat_return_version_id' => $box->vat_return_version_id, 'box_code' => $box->box_code,
            'label' => $box->label, 'amount_cents' => (int) $box->amount_cents, 'source_count' => (int) $box->source_count,
            'calculation_trace' => $box->calculation_trace,
        ];
    }
}

<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AuditCase;
use App\Models\Invoice;
use App\Models\ReconciliationException;
use App\Models\RiskIndicator;
use App\Models\TaxObligation;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Compliance\NotificationRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's assignRiskReview/
 * approveRiskAction/evaluateRisk/getRestrictedRisk -- Module 4 Phases A-B,
 * the human-authorisation gate between a risk indicator and an audit
 * case. approveRiskAction is the ONLY path in this codebase that may turn
 * a risk signal into an AuditCase -- nothing here or anywhere else
 * auto-creates one as a side effect of evaluation.
 */
class RiskService
{
    private const RULE_VERSION = 'RISK-PILOT-2026.2';
    private const SEVERITY_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'CRITICAL' => 3];

    /**
     * Human-readable labels for the rule catalogue's own three
     * `indicatorCode` values (see the `evaluate*` methods below) -- the
     * only codes any application code ever writes to `risk_indicators.
     * indicator_code`, confirmed by reading this class's own rule
     * catalogue rather than assumed.
     */
    private const INDICATOR_CATALOGUE = [
        'HIGH_VALUE_INVOICE_PATTERN' => 'High-Value Invoice Pattern',
        'RECONCILIATION_EXCEPTION_BACKLOG' => 'Reconciliation Exception Backlog',
        'OBLIGATION_OVERDUE' => 'Obligation Overdue',
    ];

    /** @return array<string, mixed> */
    public function assignReview(string $indicatorId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national risk role may assign a risk indicator for review.');
        }
        $input = ComplianceValidator::riskReviewAssignment($payload);
        $indicator = RiskIndicator::find($indicatorId);
        if (! $indicator) {
            throw new ComplianceResourceException('Risk indicator was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['indicator_id' => $indicatorId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'ASSIGN_RISK_REVIEW', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        if ($indicator->status !== 'OPEN') {
            throw new RepositoryConflictException("A review can only be assigned while the indicator is OPEN (currently {$indicator->status}).");
        }
        $officer = User::find($input['officerId']);
        if (! $officer) {
            throw new ComplianceResourceException('The assigned officer does not exist.', 404);
        }
        if ($officer->status !== 'ACTIVE') {
            throw new ComplianceResourceException('The assigned officer is not active.', 409);
        }

        $now = now();
        DB::transaction(function () use ($indicator, $indicatorId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $updated = RiskIndicator::where('id', $indicatorId)->where('status', 'OPEN')->update(['status' => 'UNDER_REVIEW', 'assigned_officer_id' => $input['officerId']]);
            if ($updated === 0) {
                // Resilience to User Errors pass (2026-09-14): a genuine
                // concurrent assignment raced this one and won between the
                // pre-check above and this guarded UPDATE -- refuse before
                // recording a command/audit trail that would claim this
                // assignment happened when it did not.
                throw new RepositoryConflictException("Risk indicator {$indicatorId} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, 'ASSIGN_RISK_REVIEW', $idempotencyKey, $requestHash, 'RISK_INDICATOR', $indicatorId, $now);
            CommandLedger::outbox('RISK_INDICATOR', $indicatorId, 'RiskReviewAssigned', $indicator->taxpayer_id, ['indicator_id' => $indicatorId, 'officer_id' => $input['officerId'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'RISK_REVIEW_ASSIGNED', 'RISK_INDICATOR', $indicatorId, ['officerId' => $input['officerId'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($indicatorId));
    }

    /**
     * The case's risk_tier is taken from the indicator's own severity, and
     * opening_reason from this decision's rationale -- never independently
     * supplied -- so every escalated case stays traceable to the exact
     * evidence and human judgement that raised it.
     *
     * @return array<string, mixed>
     */
    public function approveAction(string $indicatorId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national risk role may record a risk action decision.');
        }
        $input = ComplianceValidator::riskActionApproval($payload);
        $indicator = RiskIndicator::find($indicatorId);
        if (! $indicator) {
            throw new ComplianceResourceException('Risk indicator was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['indicator_id' => $indicatorId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_RISK_ACTION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        if ($indicator->status !== 'UNDER_REVIEW') {
            throw new RepositoryConflictException("A decision can only be recorded once a review has been assigned (currently {$indicator->status}).");
        }
        $now = now();

        if ($input['decision'] === 'DISMISS') {
            DB::transaction(function () use ($indicator, $indicatorId, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
                $updated = RiskIndicator::where('id', $indicatorId)->where('status', 'UNDER_REVIEW')->update(['status' => 'DISMISSED', 'reviewed_by' => $actor->id, 'reviewed_at' => $now]);
                if ($updated === 0) {
                    throw new RepositoryConflictException("Risk indicator {$indicatorId} was changed by another action; reload and try again.");
                }
                CommandLedger::record($actor->id, 'APPROVE_RISK_ACTION', $idempotencyKey, $requestHash, 'RISK_INDICATOR', $indicatorId, $now);
                CommandLedger::outbox('RISK_INDICATOR', $indicatorId, 'RiskActionDismissed', $indicator->taxpayer_id, ['indicator_id' => $indicatorId, 'correlation_id' => $correlationId], $now);
                AuditService::append($actor, 'RISK_ACTION_DISMISSED', 'RISK_INDICATOR', $indicatorId, ['rationale' => $input['rationale'], 'correlationId' => $correlationId], $now);
            });

            return $this->present($this->findOrFail($indicatorId));
        }

        $caseId = (string) Str::uuid();
        $caseNumber = 'CASE-'.now()->format('Y').'-'.mb_strtoupper(mb_substr(str_replace('-', '', $caseId), 0, 8));
        DB::transaction(function () use ($indicator, $indicatorId, $input, $actor, $caseId, $caseNumber, $now, $idempotencyKey, $requestHash, $correlationId) {
            AuditCase::create([
                'id' => $caseId, 'case_number' => $caseNumber, 'organisation_id' => $indicator->organisation_id, 'taxpayer_id' => $indicator->taxpayer_id,
                'case_type' => $input['caseType'], 'title' => $input['caseTitle'], 'opening_reason' => $input['rationale'], 'risk_tier' => $indicator->severity,
                'status' => 'PROPOSED', 'assigned_officer_id' => null, 'opened_by' => $actor->id, 'opened_at' => $now, 'updated_at' => $now, 'closed_at' => null,
            ]);
            $updated = RiskIndicator::where('id', $indicatorId)->where('status', 'UNDER_REVIEW')->update(['status' => 'ESCALATED_TO_CASE', 'escalated_case_id' => $caseId, 'reviewed_by' => $actor->id, 'reviewed_at' => $now]);
            if ($updated === 0) {
                // Resilience to User Errors pass (2026-09-14): see
                // assignReview()'s own comment on the identical guard --
                // here it also protects against creating a duplicate
                // AuditCase row for an indicator a concurrent request
                // already escalated or dismissed.
                throw new RepositoryConflictException("Risk indicator {$indicatorId} was changed by another action; reload and try again.");
            }
            NotificationRecorder::record(null, $indicator->taxpayer_id, 'AUDIT_CASE_OPENED', "Audit case {$caseNumber} opened", $input['caseTitle'], 'HIGH', "/cases/{$caseId}", $now);
            CommandLedger::record($actor->id, 'APPROVE_RISK_ACTION', $idempotencyKey, $requestHash, 'RISK_INDICATOR', $indicatorId, $now);
            CommandLedger::outbox('RISK_INDICATOR', $indicatorId, 'RiskEscalatedToCase', $indicator->taxpayer_id, ['indicator_id' => $indicatorId, 'case_id' => $caseId, 'case_number' => $caseNumber, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'RISK_ACTION_ESCALATED', 'RISK_INDICATOR', $indicatorId, ['rationale' => $input['rationale'], 'caseId' => $caseId, 'caseNumber' => $caseNumber, 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($indicatorId));
    }

    /**
     * Unlike every other command in this file, a replay match does NOT
     * short-circuit to stale stored data: this command's contract is
     * "current risk given current evidence," so factors are computed live
     * against whatever evidence exists now, even on a retried key. The
     * indicator writes themselves are already naturally idempotent at the
     * row level -- same rule+subject+version always resolves to the same
     * risk_indicators row, refreshed not duplicated.
     *
     * @return array<string, mixed>
     */
    public function evaluate(string $taxpayerId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national risk role may evaluate risk for a taxpayer.');
        }
        ComplianceValidator::riskEvaluationRequest($payload);
        $taxpayer = Taxpayer::with('organisation')->find($taxpayerId);
        $organisation = $taxpayer?->organisation;
        if (! $taxpayer || ! $organisation || $organisation->status !== 'ACTIVE') {
            throw new ComplianceResourceException('The taxpayer does not resolve to an active organisation.', 404);
        }

        $requestHash = CommandLedger::requestHash(['taxpayer_id' => $taxpayerId]);
        $prior = CommandLedger::prior($actor->id, 'EVALUATE_RISK', $idempotencyKey, $requestHash);

        $results = [
            $this->evaluateHighValueInvoicePattern($taxpayer->id),
            $this->evaluateReconciliationExceptionBacklog($taxpayer->id),
            $this->evaluateObligationOverdue($taxpayer->id),
        ];

        $now = now();
        $touchedIndicatorIds = [];
        DB::transaction(function () use ($results, $taxpayer, $organisation, $actor, $now, $prior, $idempotencyKey, $requestHash, $correlationId, &$touchedIndicatorIds) {
            foreach ($results as $result) {
                if (! $result['fired']) {
                    continue;
                }
                $existing = RiskIndicator::where('subject_type', 'TAXPAYER')->where('subject_id', $taxpayer->id)
                    ->where('indicator_code', $result['indicatorCode'])->where('rule_version', self::RULE_VERSION)->first();
                if ($existing && $existing->status !== 'OPEN') {
                    $touchedIndicatorIds[] = $existing->id;

                    continue;
                }
                if ($existing) {
                    RiskIndicator::where('id', $existing->id)->update(['score_bps' => $result['scoreBps'], 'severity' => $result['severity'], 'rationale' => $result['rationale'], 'detected_at' => $now]);
                    $touchedIndicatorIds[] = $existing->id;
                } else {
                    $id = (string) Str::uuid();
                    RiskIndicator::create([
                        'id' => $id, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id, 'subject_type' => 'TAXPAYER',
                        'subject_id' => $taxpayer->id, 'indicator_code' => $result['indicatorCode'], 'score_bps' => $result['scoreBps'],
                        'severity' => $result['severity'], 'rationale' => $result['rationale'], 'rule_version' => self::RULE_VERSION,
                        'decision_effect' => 'ADVISORY_ONLY', 'status' => 'OPEN', 'detected_at' => $now,
                    ]);
                    CommandLedger::outbox('RISK_INDICATOR', $id, 'RiskIndicatorRaised', $taxpayer->id, ['indicator_id' => $id, 'indicator_code' => $result['indicatorCode'], 'correlation_id' => $correlationId], $now);
                    $touchedIndicatorIds[] = $id;
                }
            }
            if (! $prior) {
                CommandLedger::record($actor->id, 'EVALUATE_RISK', $idempotencyKey, $requestHash, 'TAXPAYER', $taxpayer->id, $now);
                AuditService::append($actor, 'RISK_EVALUATED', 'TAXPAYER', $taxpayer->id, [
                    'factors' => array_map(fn ($r) => ['code' => $r['indicatorCode'], 'fired' => $r['fired']], $results),
                    'correlationId' => $correlationId,
                ], $now);
            }
        });

        $indicators = count($touchedIndicatorIds) > 0
            ? RiskIndicator::whereIn('id', $touchedIndicatorIds)->get()->map(fn (RiskIndicator $i) => $this->present($i))->values()->all()
            : [];

        return [
            'taxpayer_id' => $taxpayerId, 'evaluated_at' => $now->toISOString(), 'rule_version' => self::RULE_VERSION,
            'factors' => array_map(fn ($r) => ['indicator_code' => $r['indicatorCode'], 'fired' => $r['fired'], 'score_bps' => $r['scoreBps'], 'severity' => $r['severity'], 'rationale' => $r['rationale']], $results),
            'indicators' => $indicators,
        ];
    }

    /** Deliberately NOT taxpayer-visible at all -- risk indicators carry a NamRA-restricted classification. @return array<string, mixed> */
    public function restricted(User $actor, array $params): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Risk indicators are restricted to authorised national risk roles.');
        }
        $query = ComplianceValidator::riskIndicatorQuery($params);

        $builder = RiskIndicator::query();
        if ($query['taxpayerId']) {
            $builder->where('taxpayer_id', $query['taxpayerId']);
        }
        if ($query['status']) {
            $builder->where('status', $query['status']);
        }
        if ($query['severity']) {
            $builder->where('severity', $query['severity']);
        }
        $totalCount = (clone $builder)->count();
        $severityOrder = "CASE severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END";
        $items = $builder->orderByRaw($severityOrder)->orderByDesc('detected_at')->limit($query['limit'])->offset($query['offset'])->get();

        return ['items' => $items->map(fn (RiskIndicator $i) => $this->present($i))->values()->all(), 'totalCount' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    /**
     * An aggregate rollup of the live `restricted()` register -- national
     * oversight posture at a glance (severity/status/indicator-type
     * breakdown, the most-flagged taxpayers) rather than another page of
     * raw rows. Not period-scoped: a risk indicator is a live signal, not
     * a VAT-period figure, matching `restricted()`'s own all-time,
     * cross-taxpayer scope.
     *
     * @return array<string, mixed>
     */
    public function summary(User $actor): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Risk indicators are restricted to authorised national risk roles.');
        }

        $severityOrder = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
        $statusOrder = ['OPEN', 'UNDER_REVIEW', 'ESCALATED_TO_CASE', 'DISMISSED'];

        $bySeverityCounts = RiskIndicator::query()->selectRaw('severity, COUNT(*) as count')->groupBy('severity')->pluck('count', 'severity');
        $byStatusCounts = RiskIndicator::query()->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $byCodeCounts = RiskIndicator::query()->selectRaw('indicator_code, COUNT(*) as count')->groupBy('indicator_code')->pluck('count', 'indicator_code');

        $topRows = RiskIndicator::query()
            ->selectRaw("taxpayer_id, COUNT(*) as indicator_count, SUM(CASE WHEN severity = 'CRITICAL' THEN 1 ELSE 0 END) as critical_count, SUM(CASE WHEN status IN ('OPEN', 'UNDER_REVIEW') THEN 1 ELSE 0 END) as open_count")
            ->groupBy('taxpayer_id')->orderByDesc('indicator_count')->limit(10)->get();
        $taxpayers = Taxpayer::whereIn('id', $topRows->pluck('taxpayer_id'))->get()->keyBy('id');

        return [
            'total_count' => RiskIndicator::count(),
            'by_severity' => collect($severityOrder)
                ->map(fn ($severity) => ['severity' => $severity, 'count' => (int) ($bySeverityCounts[$severity] ?? 0)])->all(),
            'by_status' => collect($statusOrder)
                ->map(fn ($status) => ['status' => $status, 'count' => (int) ($byStatusCounts[$status] ?? 0)])->all(),
            'by_indicator_code' => collect(self::INDICATOR_CATALOGUE)
                ->map(fn ($label, $code) => ['indicator_code' => $code, 'label' => $label, 'count' => (int) ($byCodeCounts[$code] ?? 0)])->values()->all(),
            'top_taxpayers' => $topRows->map(fn ($row) => [
                'taxpayer_id' => $row->taxpayer_id,
                'legal_name' => $taxpayers[$row->taxpayer_id]->legal_name ?? null,
                'vat_number' => $taxpayers[$row->taxpayer_id]->vat_number ?? null,
                'indicator_count' => (int) $row->indicator_count,
                'critical_count' => (int) $row->critical_count,
                'open_count' => (int) $row->open_count,
            ])->all(),
        ];
    }

    // -- risk rule catalogue: a small, fixed, code-versioned set (see RiskService::RULE_VERSION) --

    /** @return array{indicatorCode: string, fired: bool, scoreBps: int, severity: string, rationale: string} */
    private function evaluateHighValueInvoicePattern(string $taxpayerId): array
    {
        $count = Invoice::where('supplier_taxpayer_id', $taxpayerId)->where('status', '!=', 'CANCELLED')->whereIn('risk_level', ['HIGH', 'CRITICAL'])->count();
        $fired = $count >= 2;

        return [
            'indicatorCode' => 'HIGH_VALUE_INVOICE_PATTERN', 'fired' => $fired, 'scoreBps' => $fired ? min(9000, 4000 + $count * 1000) : 0,
            'severity' => $count >= 5 ? 'CRITICAL' : 'HIGH', 'rationale' => "{$count} active invoice(s) independently scored HIGH or CRITICAL risk at submission time (invoice certification's own per-invoice check).",
        ];
    }

    /** @return array{indicatorCode: string, fired: bool, scoreBps: int, severity: string, rationale: string} */
    private function evaluateReconciliationExceptionBacklog(string $taxpayerId): array
    {
        $count = ReconciliationException::where('taxpayer_id', $taxpayerId)->whereIn('status', ['OPEN', 'ASSIGNED'])->count();
        $fired = $count >= 3;

        return [
            'indicatorCode' => 'RECONCILIATION_EXCEPTION_BACKLOG', 'fired' => $fired, 'scoreBps' => $fired ? min(9000, 3500 + $count * 800) : 0,
            'severity' => $count >= 8 ? 'CRITICAL' : 'HIGH', 'rationale' => "{$count} reconciliation exception(s) remain unresolved (OPEN or ASSIGNED) for this taxpayer.",
        ];
    }

    /** @return array{indicatorCode: string, fired: bool, scoreBps: int, severity: string, rationale: string} */
    private function evaluateObligationOverdue(string $taxpayerId): array
    {
        $count = TaxObligation::where('taxpayer_id', $taxpayerId)->where('status', 'PENDING')->where('due_date', '<', now()->toDateString())->count();
        $fired = $count >= 1;

        return [
            'indicatorCode' => 'OBLIGATION_OVERDUE', 'fired' => $fired, 'scoreBps' => $fired ? min(9500, 5000 + $count * 1500) : 0,
            'severity' => $count >= 3 ? 'CRITICAL' : 'HIGH', 'rationale' => "{$count} statutory obligation(s) remain PENDING past their due date.",
        ];
    }

    private function findOrFail(string $id): RiskIndicator
    {
        $indicator = RiskIndicator::find($id);
        if (! $indicator) {
            throw new ComplianceResourceException('Risk indicator was not found.', 404);
        }

        return $indicator;
    }

    /** @return array<string, mixed> */
    private function present(RiskIndicator $indicator): array
    {
        return [
            'id' => $indicator->id, 'organisation_id' => $indicator->organisation_id, 'taxpayer_id' => $indicator->taxpayer_id,
            'subject_type' => $indicator->subject_type, 'subject_id' => $indicator->subject_id, 'indicator_code' => $indicator->indicator_code,
            'score_bps' => (int) $indicator->score_bps, 'severity' => $indicator->severity, 'rationale' => $indicator->rationale,
            'rule_version' => $indicator->rule_version, 'decision_effect' => $indicator->decision_effect, 'status' => $indicator->status,
            'detected_at' => optional($indicator->detected_at)->toISOString(), 'reviewed_by' => $indicator->reviewed_by,
            'reviewed_at' => optional($indicator->reviewed_at)->toISOString(), 'assigned_officer_id' => $indicator->assigned_officer_id,
            'escalated_case_id' => $indicator->escalated_case_id,
        ];
    }
}

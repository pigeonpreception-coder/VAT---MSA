<?php

namespace App\Services\Refund;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Invoice;
use App\Models\RefundClaim;
use App\Models\RefundClaimCheck;
use App\Models\RefundClaimTransition;
use App\Models\ReconciliationException;
use App\Models\RiskIndicator;
use App\Models\TaxObligation;
use App\Models\TaxRuleSet;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's requestRefund/
 * getRefundClaimChecks/transitionRefundClaim/disputeRefund -- the refund
 * workflow the VAT-return-generation prerequisite (tax_rule_sets/
 * vat_periods/vat_return_versions) was built specifically to unblock. See
 * docs/MIGRATION_MATRIX.md's Phase 11 row and the VAT-return-generation
 * prerequisite verification section for that prerequisite's own detail.
 */
class RefundService
{
    private const RISK_SEVERITY_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'CRITICAL' => 3];

    /** @return array<string, mixed> */
    public function request(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ComplianceValidator::refundRequest($payload);
        $version = VatReturnVersion::find($input['vat_return_version_id']);
        if (! $version) {
            throw new ComplianceResourceException('VAT return version was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $version->taxpayer_id);
        if ($version->net_payable_cents >= 0) {
            throw new RepositoryConflictException('A refund request requires a negative net VAT position.');
        }
        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'REQUEST_REFUND', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(RefundClaim::findOrFail($prior));
        }
        $existing = RefundClaim::where('vat_return_version_id', $version->id)->first();
        if ($existing) {
            throw new RepositoryConflictException("A refund request already exists as {$existing->id}.");
        }

        $period = VatPeriod::findOrFail($version->vat_period_id);
        $id = (string) Str::uuid();
        $claimNumber = 'RFD-'.now()->format('Y').'-'.mb_strtoupper(mb_substr(str_replace('-', '', $id), 0, 8));
        $amount = abs((int) $version->net_payable_cents);
        $filed = $version->status === 'FILED';
        $status = $filed ? 'RECEIVED' : 'BLOCKED_RETURN_NOT_FILED';
        $amountTier = $amount >= 5_000_000 ? 'CRITICAL' : ($amount >= 1_000_000 ? 'HIGH' : 'MEDIUM');
        $riskSignal = $this->reuseTaxpayerRiskSignal($version->taxpayer_id);
        // risk_tier is the more severe of the amount-based tier and Module 4's own live
        // open-risk-indicator signal -- reusing EvaluateRisk's persisted output, never forking a second engine.
        $riskTier = $riskSignal['severity'] && self::RISK_SEVERITY_RANK[$riskSignal['severity']] > self::RISK_SEVERITY_RANK[$amountTier] ? $riskSignal['severity'] : $amountTier;
        $now = now();
        $snapshot = $this->buildSnapshot($version, $period, $now);
        $checks = $this->evaluateChecks($version, $filed, $riskSignal);

        DB::transaction(function () use ($id, $claimNumber, $version, $amount, $status, $filed, $riskTier, $actor, $now, $snapshot, $checks, $idempotencyKey, $requestHash, $correlationId) {
            RefundClaim::create([
                'id' => $id, 'claim_number' => $claimNumber, 'organisation_id' => $version->organisation_id, 'taxpayer_id' => $version->taxpayer_id,
                'vat_return_version_id' => $version->id, 'amount_cents' => $amount, 'currency' => 'NAD', 'status' => $status,
                'evidence_status' => $filed ? 'PENDING_REVIEW' : 'AWAITING_ITAS_ACKNOWLEDGEMENT', 'risk_tier' => $riskTier,
                'requested_by' => $actor->id, 'requested_at' => $now, 'approved_by' => null, 'approved_at' => null, 'payment_instruction_id' => null,
                'resume_status' => null, 'offset_amount_cents' => 0, 'net_payable_cents' => null, 'dispute_reason' => null,
                'claim_snapshot' => $snapshot['json'], 'claim_snapshot_hash' => $snapshot['hash'],
            ]);
            foreach ($checks as $check) {
                RefundClaimCheck::create([
                    'id' => (string) Str::uuid(), 'refund_claim_id' => $id, 'check_code' => $check['code'],
                    'status' => $check['status'], 'rationale' => $check['rationale'], 'evaluated_at' => $now,
                ]);
            }
            CommandLedger::record($actor->id, 'REQUEST_REFUND', $idempotencyKey, $requestHash, 'REFUND_CLAIM', $id, $now);
            CommandLedger::outbox('REFUND_CLAIM', $id, $filed ? 'RefundRequested' : 'RefundRequestBlocked', $version->taxpayer_id, [
                'refund_claim_id' => $id, 'status' => $status, 'return_version_id' => $version->id, 'snapshot_hash' => $snapshot['hash'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, $filed ? 'REFUND_REQUESTED' : 'REFUND_REQUEST_BLOCKED', 'REFUND_CLAIM', $id, [
                'claimNumber' => $claimNumber, 'status' => $status, 'amountCents' => $amount,
                'snapshotHash' => $snapshot['hash'], 'checks' => array_map(fn ($c) => ['code' => $c['code'], 'status' => $c['status']], $checks), 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present(RefundClaim::findOrFail($id));
    }

    /**
     * Ported from getRefundClaimChecks -- reads back the frozen claim
     * snapshot (written once by request() and never updated after)
     * alongside the persisted check battery. Returns null (not a thrown
     * exception) when the claim doesn't exist, matching the source's own
     * shape -- the caller decides whether that's a 404.
     *
     * @return ?array<string, mixed>
     */
    public function checks(string $claimId, User $actor): ?array
    {
        $claim = RefundClaim::find($claimId);
        if (! $claim) {
            return null;
        }
        TenantScope::requireTaxpayer($actor, $claim->taxpayer_id);
        $checks = RefundClaimCheck::where('refund_claim_id', $claimId)->orderBy('evaluated_at')->get();

        return [
            'claim' => [
                'id' => $claim->id, 'claim_number' => $claim->claim_number, 'status' => $claim->status,
                'claim_snapshot' => $claim->claim_snapshot, 'claim_snapshot_hash' => $claim->claim_snapshot_hash,
            ],
            'checks' => $checks->map(fn (RefundClaimCheck $check) => [
                'check_code' => $check->check_code, 'status' => $check->status, 'rationale' => $check->rationale,
                'evaluated_at' => optional($check->evaluated_at)->toISOString(),
            ])->all(),
        ];
    }

    /**
     * Ported from transitionRefundClaim -- the single code path that can
     * change a refund claim's status, officer-only. See that function's own
     * doc comment (compliance-repository.ts) for the full maker-checker
     * rationale this mirrors: universal self-review denial, plus a second,
     * narrower "distinct actor from the immediately preceding transition"
     * check for the material PAYMENT_AUTHORISATION->PAYMENT_PENDING APPROVE
     * and for every APPROVE on a HIGH/CRITICAL risk_tier claim.
     *
     * @return array<string, mixed>
     */
    public function transition(string $claimId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national refund role may transition a refund claim.');
        }
        $input = ComplianceValidator::refundClaimTransition($payload);
        $claim = RefundClaim::find($claimId);
        if (! $claim) {
            throw new ComplianceResourceException('Refund claim was not found.', 404);
        }
        if ($claim->requested_by === $actor->id) {
            throw new AuthorizationException('Maker-checker separation prevents reviewing your own refund request.');
        }
        if ($input['action'] === 'APPROVE') {
            $isMaterialOutcome = $claim->status === 'PAYMENT_AUTHORISATION';
            $isEnhancedLane = in_array($claim->risk_tier, ['HIGH', 'CRITICAL'], true);
            if ($isMaterialOutcome || $isEnhancedLane) {
                $lastTransition = RefundClaimTransition::where('refund_claim_id', $claimId)->orderByDesc('occurred_at')->first();
                if ($lastTransition && $lastTransition->actor_id === $actor->id) {
                    throw new AuthorizationException($isMaterialOutcome
                        ? 'Maker-checker separation requires a distinct reviewing officer to authorise payment.'
                        : "This claim's risk tier requires a distinct reviewing officer at every stage.");
                }
            }
        }
        $requestHash = CommandLedger::requestHash(['claim_id' => $claim->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'TRANSITION_REFUND_CLAIM', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(RefundClaim::findOrFail($prior));
        }

        $staticTarget = ComplianceValidator::assertRefundClaimTransition($input['action'], $claim->status);
        if ($input['action'] === 'RESUME') {
            if (! $claim->resume_status) {
                throw new ComplianceResourceException('This refund claim has no recorded state to resume into.', 409);
            }
            $targetStatus = $claim->resume_status;
        } else {
            $targetStatus = $staticTarget;
        }
        $nextResumeFrom = in_array($input['action'], ['REQUEST_INFORMATION', 'HOLD'], true) ? $claim->status : null;

        if ($input['action'] === 'RECHECK_ELIGIBILITY') {
            $version = VatReturnVersion::find($claim->vat_return_version_id);
            if (! $version || $version->status !== 'FILED') {
                throw new RepositoryConflictException('The underlying VAT return is still not filed; the claim cannot re-enter review yet.');
            }
        }

        $offsetAmountCents = null;
        $netPayableCents = null;
        if ($input['action'] === 'APPROVE' && $claim->status === 'PAYMENT_AUTHORISATION') {
            $debt = (int) TaxObligation::where('taxpayer_id', $claim->taxpayer_id)->where('status', 'PENDING')->sum('amount_cents');
            $offsetAmountCents = min((int) $claim->amount_cents, $debt);
            $netPayableCents = (int) $claim->amount_cents - $offsetAmountCents;
        }

        $now = now();
        $fromStatus = $claim->status;
        $eventType = 'RefundClaim'.mb_substr($input['action'], 0, 1).mb_strtolower(str_replace('_', '', mb_substr($input['action'], 1)));
        DB::transaction(function () use ($claim, $claimId, $targetStatus, $nextResumeFrom, $offsetAmountCents, $netPayableCents, $input, $fromStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $eventType) {
            RefundClaim::where('id', $claimId)->update([
                'status' => $targetStatus, 'resume_status' => $nextResumeFrom,
                'approved_by' => $targetStatus === 'PAYMENT_PENDING' ? $actor->id : $claim->approved_by,
                'approved_at' => $targetStatus === 'PAYMENT_PENDING' ? $now : $claim->approved_at,
                'offset_amount_cents' => $offsetAmountCents ?? $claim->offset_amount_cents,
                'net_payable_cents' => $netPayableCents ?? $claim->net_payable_cents,
            ]);
            RefundClaimTransition::create([
                'id' => (string) Str::uuid(), 'refund_claim_id' => $claimId, 'action' => $input['action'],
                'from_status' => $fromStatus, 'to_status' => $targetStatus, 'actor_id' => $actor->id,
                'findings' => $input['findings'], 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'TRANSITION_REFUND_CLAIM', $idempotencyKey, $requestHash, 'REFUND_CLAIM', $claimId, $now);
            CommandLedger::outbox('REFUND_CLAIM', $claimId, $eventType, $claim->taxpayer_id, array_filter([
                'refund_claim_id' => $claimId, 'action' => $input['action'], 'from_status' => $fromStatus, 'to_status' => $targetStatus, 'correlation_id' => $correlationId,
                'offset_amount_cents' => $offsetAmountCents, 'net_payable_cents' => $netPayableCents,
            ], fn ($v) => $v !== null), $now);
            // One audit_events row per command -- the offset computation is folded into
            // this same row rather than a second AuditService::append call in this transaction.
            AuditService::append($actor, "REFUND_CLAIM_{$input['action']}", 'REFUND_CLAIM', $claimId, array_filter([
                'action' => $input['action'], 'fromStatus' => $fromStatus, 'toStatus' => $targetStatus, 'findings' => $input['findings'], 'correlationId' => $correlationId,
                'offsetAmountCents' => $offsetAmountCents, 'netPayableCents' => $netPayableCents,
            ], fn ($v) => $v !== null), $now);
        });

        return $this->present(RefundClaim::findOrFail($claimId));
    }

    /**
     * Ported from disputeRefund -- the one taxpayer-initiated refund claim
     * action (REJECTED -> DISPUTED). The caller must be the original
     * requester of this specific claim, not merely in-scope for the
     * taxpayer generally -- mirroring the same principle transition()
     * enforces the other direction via its self-review denial.
     *
     * @return array<string, mixed>
     */
    public function dispute(string $claimId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ComplianceValidator::refundClaimTransition($payload);
        if ($input['action'] !== 'DISPUTE') {
            throw new ComplianceResourceException('Only a DISPUTE action may be submitted through this endpoint.', 400);
        }
        $claim = RefundClaim::find($claimId);
        if (! $claim) {
            throw new ComplianceResourceException('Refund claim was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $claim->taxpayer_id);
        if ($claim->requested_by !== $actor->id) {
            throw new AuthorizationException("Only the original requester may dispute this refund claim's outcome.");
        }
        $requestHash = CommandLedger::requestHash(['claim_id' => $claim->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'DISPUTE_REFUND_CLAIM', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(RefundClaim::findOrFail($prior));
        }

        $targetStatus = ComplianceValidator::assertRefundClaimTransition('DISPUTE', $claim->status);
        $now = now();
        $fromStatus = $claim->status;
        DB::transaction(function () use ($claim, $claimId, $targetStatus, $input, $fromStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            RefundClaim::where('id', $claimId)->update(['status' => $targetStatus, 'dispute_reason' => $input['findings']]);
            RefundClaimTransition::create([
                'id' => (string) Str::uuid(), 'refund_claim_id' => $claimId, 'action' => 'DISPUTE',
                'from_status' => $fromStatus, 'to_status' => $targetStatus, 'actor_id' => $actor->id,
                'findings' => $input['findings'], 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'DISPUTE_REFUND_CLAIM', $idempotencyKey, $requestHash, 'REFUND_CLAIM', $claimId, $now);
            CommandLedger::outbox('REFUND_CLAIM', $claimId, 'RefundClaimDisputed', $claim->taxpayer_id, [
                'refund_claim_id' => $claimId, 'from_status' => $fromStatus, 'to_status' => $targetStatus, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'REFUND_CLAIM_DISPUTE', 'REFUND_CLAIM', $claimId, [
                'fromStatus' => $fromStatus, 'toStatus' => $targetStatus, 'reason' => $input['findings'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present(RefundClaim::findOrFail($claimId));
    }

    /** @return array{severity: ?string, openCount: int} */
    private function reuseTaxpayerRiskSignal(string $taxpayerId): array
    {
        $severities = RiskIndicator::where('taxpayer_id', $taxpayerId)->where('status', 'OPEN')->pluck('severity');
        $highest = null;
        foreach ($severities as $severity) {
            if (! $highest || (self::RISK_SEVERITY_RANK[$severity] ?? 0) > (self::RISK_SEVERITY_RANK[$highest] ?? 0)) {
                $highest = $severity;
            }
        }

        return ['severity' => $highest, 'openCount' => $severities->count()];
    }

    /** @return array{json: string, hash: string} */
    private function buildSnapshot(VatReturnVersion $version, VatPeriod $period, \Illuminate\Support\Carbon $now): array
    {
        $taxRuleSet = TaxRuleSet::find($version->tax_rule_set_id);
        $reconciliation = ReconciliationException::where('taxpayer_id', $version->taxpayer_id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('OPEN','ASSIGNED') THEN 1 ELSE 0 END) as open")
            ->first();
        $invoices = Invoice::where('supplier_taxpayer_id', $version->taxpayer_id)->where('status', '!=', 'CANCELLED')
            ->whereBetween('issue_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->orderBy('id')->get(['id', 'payload_hash']);
        $invoiceHash = hash('sha256', AuditService::canonicalJson($invoices->map(fn ($i) => ['id' => $i->id, 'payloadHash' => $i->payload_hash])->all()));
        $snapshot = [
            'returnVersion' => [
                'id' => $version->id, 'versionNumber' => $version->version_number, 'outputTaxCents' => (int) $version->output_tax_cents,
                'inputTaxCents' => (int) $version->input_tax_cents, 'adjustmentCents' => (int) $version->adjustment_cents,
                'netPayableCents' => (int) $version->net_payable_cents, 'status' => $version->status,
                'ledgerSnapshotHash' => $version->ledger_snapshot_hash, 'generatedAt' => optional($version->generated_at)->toISOString(),
                'periodCode' => $period->period_code,
            ],
            'taxRuleSet' => $taxRuleSet ? [
                'id' => $taxRuleSet->id, 'jurisdiction' => $taxRuleSet->jurisdiction, 'version' => $taxRuleSet->version,
                'standardRateBps' => (int) $taxRuleSet->standard_rate_bps,
            ] : null,
            'reconciliation' => ['openExceptions' => (int) ($reconciliation->open ?? 0), 'totalExceptions' => (int) ($reconciliation->total ?? 0)],
            'invoiceEvidence' => ['count' => $invoices->count(), 'hash' => $invoiceHash],
            'frozenAt' => $now->toISOString(),
        ];
        $json = AuditService::canonicalJson($snapshot);

        return ['json' => $json, 'hash' => hash('sha256', $json)];
    }

    /**
     * Ported from evaluateRefundClaimChecks -- "each its own testable
     * policy with an explainable pass/fail, never a black-box composite
     * score." IDENTITY_VERIFICATION/BANK_ACCOUNT_OWNERSHIP/
     * SANCTIONS_SCREENING are explicitly NOT_CONFIGURED rather than a
     * fabricated PASS: no identity/bank/AML screening provider exists
     * anywhere in this codebase, matching this deployment's own
     * established convention (ItasIdentityPort's own unconditional-
     * unavailable stub, etc.) of saying so explicitly.
     *
     * @return list<array{code: string, status: string, rationale: string}>
     */
    private function evaluateChecks(VatReturnVersion $version, bool $filed, array $riskSignal): array
    {
        $debt = (int) TaxObligation::where('taxpayer_id', $version->taxpayer_id)->where('status', 'PENDING')->sum('amount_cents');
        $claimCount = RefundClaim::where('taxpayer_id', $version->taxpayer_id)
            ->where('requested_at', '>=', now()->subDays(90)->format('Y-m-d H:i:s'))->count() + 1;
        $riskFails = in_array($riskSignal['severity'], ['HIGH', 'CRITICAL'], true);

        return [
            ['code' => 'ELIGIBILITY_NEGATIVE_NET_POSITION', 'status' => 'PASS', 'rationale' => "The return's net VAT position is {$version->net_payable_cents} cents, a negative balance confirmed before this claim was accepted."],
            ['code' => 'ELIGIBILITY_RETURN_FILED', 'status' => $filed ? 'PASS' : 'FAIL', 'rationale' => $filed ? 'The underlying VAT return is FILED.' : 'The underlying VAT return has not been filed yet; the claim is blocked pending filing.'],
            ['code' => 'DUPLICATE_CLAIM', 'status' => 'PASS', 'rationale' => 'No other refund claim exists for this VAT return version (enforced by a unique constraint on refund_claims.vat_return_version_id).'],
            ['code' => 'DEBT_OFFSET_PREVIEW', 'status' => 'PASS', 'rationale' => "{$debt} cents of PENDING statutory debt is on record today. Informational only -- the authoritative offset is recomputed live against current obligations at PAYMENT_AUTHORISATION, never taken from this preview."],
            ['code' => 'ANOMALY_CLAIM_FREQUENCY', 'status' => $claimCount >= 3 ? 'FAIL' : 'PASS', 'rationale' => "{$claimCount} refund claim(s) from this taxpayer within the trailing 90 days, including this one. Advisory only -- does not block claim creation."],
            [
                'code' => 'RISK_INDICATOR_SIGNAL', 'status' => $riskFails ? 'FAIL' : 'PASS',
                'rationale' => $riskSignal['openCount']
                    ? "{$riskSignal['openCount']} open risk indicator(s) on record for this taxpayer from Module 4's EvaluateRisk, highest severity {$riskSignal['severity']}. This is the same persisted signal EvaluateRisk itself produces, not a separately forked risk assessment -- it also elevates this claim's risk_tier below and, at ".($riskFails ? 'HIGH/CRITICAL, ' : '').'routes it to the enhanced maker-checker lane.'
                    : "No open risk indicators on record for this taxpayer in Module 4's risk_indicators table.",
            ],
            ['code' => 'IDENTITY_VERIFICATION', 'status' => 'NOT_CONFIGURED', 'rationale' => 'No identity verification provider is configured for this pilot deployment; this check cannot be evaluated.'],
            ['code' => 'BANK_ACCOUNT_OWNERSHIP', 'status' => 'NOT_CONFIGURED', 'rationale' => 'No bank/account-ownership verification provider is configured for this pilot deployment; this check cannot be evaluated.'],
            ['code' => 'SANCTIONS_SCREENING', 'status' => 'NOT_CONFIGURED', 'rationale' => 'No sanctions/AML screening provider is configured for this pilot deployment; this check cannot be evaluated.'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(RefundClaim $claim): array
    {
        return [
            'id' => $claim->id, 'claim_number' => $claim->claim_number, 'organisation_id' => $claim->organisation_id,
            'taxpayer_id' => $claim->taxpayer_id, 'vat_return_version_id' => $claim->vat_return_version_id,
            'amount_cents' => (int) $claim->amount_cents, 'currency' => $claim->currency, 'status' => $claim->status,
            'evidence_status' => $claim->evidence_status, 'risk_tier' => $claim->risk_tier, 'requested_by' => $claim->requested_by,
            'requested_at' => optional($claim->requested_at)->toISOString(), 'approved_by' => $claim->approved_by,
            'approved_at' => optional($claim->approved_at)->toISOString(), 'payment_instruction_id' => $claim->payment_instruction_id,
            'resume_status' => $claim->resume_status, 'offset_amount_cents' => (int) $claim->offset_amount_cents,
            'net_payable_cents' => $claim->net_payable_cents === null ? null : (int) $claim->net_payable_cents,
            'dispute_reason' => $claim->dispute_reason, 'claim_snapshot_hash' => $claim->claim_snapshot_hash,
        ];
    }
}

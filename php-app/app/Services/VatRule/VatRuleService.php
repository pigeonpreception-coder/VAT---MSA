<?php

namespace App\Services\VatRule;

use App\Domain\VatRule\VatRuleValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\VatRuleValidationException;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\VatRule;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Invoice\VatRuleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/vat-rule-repository.ts's listVatRules/proposeVatRule/
 * approveVatRule/evaluateVatRule (Module 2 Phase A) -- the standalone
 * VAT-rule evaluate/propose/approve routes, the last narrow gap Phase 9
 * (invoices and VAT) deferred. Writes directly to `command_idempotency`
 * via CommandLedger, matching a real 2026-08-27 fix in the source itself
 * (SECURITY_GAP_ASSESSMENT.md item #8: this repository previously had zero
 * idempotency-key handling at all, unlike every other repository in the
 * codebase).
 */
class VatRuleService
{
    private const COUNTRY = 'NA';

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return VatRule::orderBy('tax_category')->orderByDesc('version')->get()
            ->map(fn (VatRule $rule) => $this->present($rule))->values()->all();
    }

    /**
     * ProposeVatRule: a DRAFT until a different officer approves it
     * (approve() below). version is the next integer for this
     * (tax_category, country) lineage, computed from the current max --
     * never caller-supplied, so a proposal can never collide with or skip
     * an existing version.
     *
     * @return array<string, mixed>
     */
    public function propose(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $proposal = VatRuleValidator::proposal($payload);
        $requestHash = CommandLedger::requestHash($proposal);
        $prior = CommandLedger::prior($actor->id, 'PROPOSE_VAT_RULE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(VatRule::findOrFail($prior));
        }

        $currentVersion = (int) (VatRule::where('tax_category', $proposal['taxCategory'])->where('country', self::COUNTRY)->max('version') ?? 0);
        $version = $currentVersion + 1;
        $id = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($proposal, $actor, $id, $version, $now, $idempotencyKey, $requestHash, $correlationId) {
            VatRule::create([
                'id' => $id, 'tax_category' => $proposal['taxCategory'], 'country' => self::COUNTRY, 'rate_bps' => $proposal['rateBps'],
                'status' => 'DRAFT', 'version' => $version, 'effective_from' => $proposal['effectiveFrom'], 'effective_to' => null,
                'proposed_by' => $actor->id, 'proposed_at' => $now, 'approved_by' => null, 'approved_at' => null,
                'approval_reason' => null, 'proposal_reason' => $proposal['reason'], 'superseded_by' => null,
            ]);
            CommandLedger::record($actor->id, 'PROPOSE_VAT_RULE', $idempotencyKey, $requestHash, 'VAT_RULE', $id, $now);
            $this->outbox($id, 'VatRuleProposed', $proposal['taxCategory'], [
                'ruleId' => $id, 'taxCategory' => $proposal['taxCategory'], 'rateBps' => $proposal['rateBps'], 'version' => $version, 'correlationId' => $correlationId,
            ], $now);
            AuditService::append($actor, 'VAT_RULE_PROPOSED', 'VAT_RULE', $id, [
                'taxCategory' => $proposal['taxCategory'], 'rateBps' => $proposal['rateBps'], 'version' => $version, 'effectiveFrom' => $proposal['effectiveFrom'],
            ], $now);
        });

        return $this->present(VatRule::findOrFail($id));
    }

    /**
     * ApproveVatRule: self-approval denied (the proposing officer cannot
     * also approve -- segregation of duties, the same pattern used for
     * registration decisions and access requests). On approval, retires
     * whichever rule currently governs this (tax_category, country) with
     * an open-ended effective_to, closing it exactly where the new rule
     * begins so the two ranges never overlap and evaluate() never has two
     * candidates.
     *
     * @return array<string, mixed>
     */
    public function approve(string $ruleId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $approval = VatRuleValidator::approval($payload);
        $rule = VatRule::find($ruleId);
        if (! $rule) {
            throw new VatRuleValidationException([
                ['code' => 'RULE_NOT_FOUND', 'path' => '/rule_id', 'message' => 'The VAT rule proposal does not exist.'],
            ]);
        }

        $requestHash = CommandLedger::requestHash(['ruleId' => $ruleId, 'approval' => $approval]);
        $prior = CommandLedger::prior($actor->id, 'APPROVE_VAT_RULE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(VatRule::findOrFail($prior));
        }

        if ($rule->status !== 'DRAFT') {
            throw new RepositoryConflictException("This VAT rule is already {$rule->status}.");
        }
        if ($actor->id === $rule->proposed_by) {
            throw new VatRuleValidationException([
                ['code' => 'SELF_APPROVAL_DENIED', 'path' => '/actor', 'message' => 'The proposing officer cannot approve their own VAT rule.'],
            ]);
        }

        $superseding = VatRule::where('tax_category', $rule->tax_category)->where('country', $rule->country)
            ->where('status', 'APPROVED')->whereNull('effective_to')->first();
        if ($superseding && $rule->effective_from <= $superseding->effective_from) {
            throw new VatRuleValidationException([
                ['code' => 'EFFECTIVE_FROM_NOT_FORWARD', 'path' => '/effective_from', 'message' => "This rule must take effect after the currently approved rule's effective date ({$superseding->effective_from->toDateString()})."],
            ]);
        }

        $now = now();
        DB::transaction(function () use ($rule, $superseding, $actor, $now, $approval, $idempotencyKey, $requestHash, $correlationId) {
            VatRule::where('id', $rule->id)->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => $now, 'approval_reason' => $approval['reason']]);
            CommandLedger::record($actor->id, 'APPROVE_VAT_RULE', $idempotencyKey, $requestHash, 'VAT_RULE', $rule->id, $now);
            $this->outbox($rule->id, 'VatRuleApproved', $rule->tax_category, [
                'ruleId' => $rule->id, 'taxCategory' => $rule->tax_category, 'rateBps' => $rule->rate_bps, 'version' => $rule->version, 'correlationId' => $correlationId,
            ], $now);
            AuditService::append($actor, 'VAT_RULE_APPROVED', 'VAT_RULE', $rule->id, [
                'taxCategory' => $rule->tax_category, 'rateBps' => $rule->rate_bps, 'version' => $rule->version, 'reason' => $approval['reason'],
            ], $now);
            if ($superseding) {
                VatRule::where('id', $superseding->id)->update(['effective_to' => $rule->effective_from->toDateString(), 'superseded_by' => $rule->id]);
            }
        });

        return $this->present(VatRule::findOrFail($rule->id));
    }

    /**
     * EvaluateVAT, as a standalone dry-run query -- lets an ERP integrator
     * preview the applicable rate before building an invoice. Fails closed
     * (throws) rather than returning any default when no approved rule is
     * bound.
     *
     * @return array<string, mixed>
     */
    public function evaluate(mixed $taxCategoryInput, mixed $dateInput): array
    {
        $query = VatRuleValidator::evaluationQuery($taxCategoryInput, $dateInput);
        $rule = VatRuleResolver::applicable($query['taxCategory'], $query['effectiveDate']);
        if (! $rule) {
            throw new VatRuleValidationException([
                ['code' => 'NO_APPROVED_VAT_RULE', 'path' => '/tax_category', 'message' => "No approved VAT rule is bound for {$query['taxCategory']} on {$query['effectiveDate']}."],
            ]);
        }

        return [
            'tax_category' => $query['taxCategory'], 'effective_date' => $query['effectiveDate'],
            'rule' => [
                'id' => $rule->id, 'rate_bps' => (int) $rule->rate_bps, 'version' => (int) $rule->version,
                'effective_from' => $rule->effective_from->toDateString(), 'effective_to' => optional($rule->effective_to)->toDateString(),
            ],
        ];
    }

    private function outbox(string $ruleId, string $eventType, string $partitionKey, array $payload, \DateTimeInterface $now): void
    {
        OutboxEvent::create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'VAT_RULE', 'aggregate_id' => $ruleId, 'event_type' => $eventType,
            'event_version' => 1, 'partition_key' => $partitionKey, 'payload' => AuditService::canonicalJson($payload),
            'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => $now, 'available_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(VatRule $rule): array
    {
        return [
            'id' => $rule->id, 'tax_category' => $rule->tax_category, 'country' => $rule->country, 'rate_bps' => (int) $rule->rate_bps,
            'status' => $rule->status, 'version' => (int) $rule->version, 'effective_from' => $rule->effective_from->toDateString(),
            'effective_to' => optional($rule->effective_to)->toDateString(), 'proposed_by' => $rule->proposed_by,
            'proposed_at' => optional($rule->proposed_at)->toISOString(), 'approved_by' => $rule->approved_by,
            'approved_at' => optional($rule->approved_at)->toISOString(), 'proposal_reason' => $rule->proposal_reason,
            'approval_reason' => $rule->approval_reason, 'superseded_by' => $rule->superseded_by,
        ];
    }
}

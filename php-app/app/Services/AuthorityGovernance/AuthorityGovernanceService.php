<?php

namespace App\Services\AuthorityGovernance;

use App\Domain\AuthorityGovernance\AuthorityGovernanceValidator;
use App\Exceptions\AuthorityGovernanceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\TaxAuthority;
use App\Models\TaxAuthorityAdministrator;
use App\Models\TaxAuthorityGovernanceEvent;
use App\Models\TaxAuthorityOnboardingCase;
use App\Models\TaxAuthorityOnboardingDecision;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/authority-governance-repository.ts -- the backend
 * for the NamRA Administration portal (app/portal/namra-admin/page.tsx),
 * a genuinely new module this migration had never touched before (see
 * docs/MIGRATION_MATRIX.md's own "NamRA Administration portal: deferred"
 * section for why it was scoped out of the five other portal dashboards'
 * own slice). Covers getAuthorityGovernanceSnapshot/
 * createAuthorityOnboardingCase/decideAuthorityOnboardingCase in full.
 *
 * `authorityGovernanceLocalWritesEnabled()` (source) checks a
 * VAT_MSA_ENVIRONMENT/NODE_ENV combination this PHP port has no
 * equivalent of -- simplified to Laravel's own environment idiom:
 * writes are enabled everywhere except `app()->environment('production')`,
 * collapsing the source's separate "staging, only with an explicit
 * synthetic-data opt-in" branch into "disabled", the safer default. This
 * whole deployment is documented throughout as a local/staging pilot
 * (docs/DEPLOYMENT.md), so the practical effect is identical: enabled
 * here, disabled in a real production deployment.
 *
 * Production Tax Authority activation itself (`PRODUCTION_ACTIVATED`
 * status, the federation `PRODUCTION` environment truly going live) has
 * no command anywhere in the source either -- `productionActivationEnabled`
 * is hardcoded `false` in the snapshot and every response's own
 * `production_activation_effect` is hardcoded `false` too, reproduced
 * identically here.
 */
class AuthorityGovernanceService
{
    /** @return array<string, mixed> */
    public function getSnapshot(User $actor): array
    {
        $authorities = DB::table('tax_authorities as ta')
            ->join('tax_authority_administrators as admin', function ($join) use ($actor) {
                $join->on('admin.tax_authority_id', '=', 'ta.id')->where('admin.user_id', $actor->id)->where('admin.status', 'ACTIVE');
            })
            ->join('tax_jurisdictions as tj', 'tj.id', '=', 'ta.jurisdiction_id')
            ->join('countries as c', 'c.code', '=', 'tj.country_code')
            ->distinct()
            ->orderBy('ta.name')
            ->get(['ta.id', 'ta.jurisdiction_id', 'ta.code', 'ta.name', 'ta.status', 'tj.name as jurisdiction_name', 'c.name as country_name']);

        if ($authorities->isEmpty()) {
            throw new AuthorizationException('No governed Tax Authority administration scope is assigned to this identity.');
        }
        $ids = $authorities->pluck('id')->all();

        $units = DB::table('tax_authority_units')->whereIn('tax_authority_id', $ids)
            ->orderBy('tax_authority_id')->orderBy('parent_unit_id')->orderBy('name')
            ->get(['id', 'tax_authority_id', 'parent_unit_id', 'code', 'name', 'unit_type', 'status']);

        $roles = DB::table('tax_authority_role_definitions')->orderBy('duty_class')->orderBy('name')
            ->get(['code', 'name', 'duty_class', 'assurance_required', 'protected', 'status']);

        $assignments = DB::table('tax_authority_role_assignments as a')
            ->join('tax_authority_role_definitions as r', 'r.code', '=', 'a.role_code')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.tax_authority_id', $ids)
            ->orderBy('a.tax_authority_id')->orderBy('r.duty_class')->orderBy('u.name')
            ->get(['a.id', 'a.tax_authority_id', 'a.authority_unit_id', 'a.role_code', 'r.name as role_name', 'r.duty_class',
                'u.name as display_name', 'u.email', 'a.scope', 'a.status', 'a.effective_from', 'a.effective_to']);

        $federation = DB::table('tax_authority_federation_connections as f')
            ->join('identity_providers as p', 'p.id', '=', 'f.identity_provider_id')
            ->whereIn('f.tax_authority_id', $ids)
            ->orderBy('f.tax_authority_id')->orderBy('f.environment')
            ->get(['f.id', 'f.tax_authority_id', 'p.provider_key', 'p.display_name', 'f.environment', 'f.protocol', 'f.status',
                'f.assurance_profile', 'f.checked_at', 'f.expires_at', 'f.updated_at']);

        $cases = DB::table('tax_authority_onboarding_cases as c')
            ->join('tax_authorities as ta', 'ta.id', '=', 'c.tax_authority_id')
            ->join('users as requester', 'requester.id', '=', 'c.requested_by')
            ->leftJoin('tax_authority_onboarding_decisions as d', 'd.onboarding_case_id', '=', 'c.id')
            ->leftJoin('users as decider', 'decider.id', '=', 'd.decided_by')
            ->whereIn('c.tax_authority_id', $ids)
            ->orderByDesc('c.submitted_at')
            ->get(['c.id', 'c.tax_authority_id', 'ta.name as authority_name', 'c.target_environment', 'c.status', 'c.purpose',
                'c.evidence_bundle_hash', 'c.readiness_reference', 'c.requested_by', 'requester.name as requester_name',
                'c.submitted_at', 'c.approved_at', 'c.activated_at', 'c.updated_at',
                'd.decision_type', 'd.reason as decision_reason', 'decider.name as decided_by_name']);

        $reviews = DB::table('tax_authority_access_reviews')->whereIn('tax_authority_id', $ids)->orderByDesc('period_start')
            ->get(['id', 'tax_authority_id', 'review_type', 'period_start', 'due_at', 'status', 'owner_id', 'completed_by', 'completed_at']);

        return [
            'authorities' => $this->rows($authorities), 'units' => $this->rows($units), 'roles' => $this->rows($roles),
            'assignments' => $this->rows($assignments), 'federation' => $this->rows($federation),
            'onboardingCases' => $this->rows($cases), 'accessReviews' => $this->rows($reviews),
            'productionActivationEnabled' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function createOnboardingCase(User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        $this->requireLocalWritesEnabled();
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $submission = AuthorityGovernanceValidator::onboardingSubmission($payload);
        $this->requireAuthorityScope($actor, $submission['tax_authority_id']);

        $requestHash = CommandLedger::requestHash($submission);
        $prior = CommandLedger::prior($actor->id, 'CREATE_AUTHORITY_ONBOARDING_CASE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($prior, $actor);
        }
        $duplicate = TaxAuthorityOnboardingCase::where('tax_authority_id', $submission['tax_authority_id'])
            ->where('target_environment', $submission['target_environment'])
            ->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'LOCAL_STAGING_READY', 'BLOCKED_EXTERNAL'])
            ->first();
        if ($duplicate) {
            throw new RepositoryConflictException("An open {$submission['target_environment']} authority-onboarding case already exists as {$duplicate->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        $blocked = $submission['target_environment'] === 'PRODUCTION';
        $status = $blocked ? 'BLOCKED_EXTERNAL' : 'SUBMITTED';
        $eventType = $blocked ? 'ProductionAuthorityOnboardingBlocked' : 'TaxAuthorityOnboardingRequested';
        $reasonCode = $blocked ? 'PRODUCTION_AUTHORITY_EVIDENCE_REQUIRED' : 'LOCAL_STAGING_REVIEW_REQUIRED';

        DB::transaction(function () use ($id, $submission, $status, $eventType, $reasonCode, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            TaxAuthorityOnboardingCase::create([
                'id' => $id, 'tax_authority_id' => $submission['tax_authority_id'], 'target_environment' => $submission['target_environment'],
                'status' => $status, 'purpose' => $submission['purpose'], 'evidence_bundle_hash' => $submission['evidence_bundle_hash'],
                'readiness_reference' => $submission['readiness_reference'], 'requested_by' => $actor->id,
                'submitted_at' => $now, 'approved_at' => null, 'activated_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            TaxAuthorityGovernanceEvent::create([
                'id' => (string) Str::uuid(), 'tax_authority_id' => $submission['tax_authority_id'], 'onboarding_case_id' => $id,
                'event_type' => $eventType, 'from_status' => null, 'to_status' => $status, 'reason_code' => $reasonCode,
                'evidence_hash' => $submission['evidence_bundle_hash'], 'actor_id' => $actor->id, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_AUTHORITY_ONBOARDING_CASE', $idempotencyKey, $requestHash, 'TAX_AUTHORITY_ONBOARDING_CASE', $id, $now);
            CommandLedger::outbox('TAX_AUTHORITY_ONBOARDING', $id, $eventType, $submission['tax_authority_id'], [
                'onboarding_case_id' => $id, 'tax_authority_id' => $submission['tax_authority_id'],
                'target_environment' => $submission['target_environment'], 'status' => $status, 'reason_code' => $reasonCode,
                'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, $eventType, 'TAX_AUTHORITY_ONBOARDING_CASE', $id, [
                'authorityId' => $submission['tax_authority_id'], 'targetEnvironment' => $submission['target_environment'],
                'status' => $status, 'reasonCode' => $reasonCode, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present($id, $actor);
    }

    /** @return array<string, mixed> */
    public function decideOnboardingCase(User $actor, string $caseId, array $payload, string $idempotencyKey, string $correlationId, string $stepUpEvidenceReference): array
    {
        $this->requireLocalWritesEnabled();
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $decision = AuthorityGovernanceValidator::onboardingDecision($payload);
        $current = $this->findForActor($caseId, $actor);
        if (! $current) {
            throw new AuthorizationException('The authority-onboarding case is unavailable in the actor\'s authority scope.');
        }
        $this->requireAuthorityScope($actor, $current->tax_authority_id);
        $this->requireCurrentAuthorityReview($current->tax_authority_id);
        if ($current->requested_by === $actor->id) {
            throw new AuthorizationException('AUTHORITY_ONBOARDING_SELF_APPROVAL_DENIED: The onboarding requester cannot decide the same case.');
        }
        if (! in_array($current->status, ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            throw new RepositoryConflictException("Authority-onboarding case {$caseId} is already {$current->status}.");
        }
        if ($decision['decision'] === 'APPROVE_LOCAL_STAGING' && $current->target_environment !== 'LOCAL_STAGING') {
            throw new RepositoryConflictException('Production authority onboarding cannot be approved through the local/staging decision command.');
        }

        $requestHash = CommandLedger::requestHash(['case_id' => $caseId, 'decision' => $decision]);
        $prior = CommandLedger::prior($actor->id, 'DECIDE_AUTHORITY_ONBOARDING_CASE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($prior, $actor);
        }

        $now = now();
        $fromStatus = $current->status;
        $approve = $decision['decision'] === 'APPROVE_LOCAL_STAGING';
        $nextStatus = $approve ? 'LOCAL_STAGING_READY' : 'REJECTED';
        $decisionType = $approve ? 'LOCAL_STAGING_APPROVAL' : 'REJECTION';
        $eventType = $approve ? 'TaxAuthorityLocalStagingApproved' : 'TaxAuthorityOnboardingRejected';
        $reasonCode = $approve ? 'LOCAL_STAGING_ONLY_NO_PRODUCTION_EFFECT' : 'AUTHORITY_ONBOARDING_REJECTED';
        $evidenceHash = hash('sha256', AuditService::canonicalJson([
            'caseId' => $caseId, 'decisionType' => $decisionType, 'decision' => $decision['decision'], 'reason' => $decision['reason'],
            'decidedBy' => $actor->id, 'stepUpEvidenceReference' => $stepUpEvidenceReference, 'now' => $now->toISOString(),
        ]));

        DB::transaction(function () use ($current, $caseId, $nextStatus, $decisionType, $decision, $fromStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $eventType, $reasonCode, $evidenceHash, $stepUpEvidenceReference) {
            TaxAuthorityOnboardingDecision::create([
                'id' => (string) Str::uuid(), 'onboarding_case_id' => $caseId, 'decision_type' => $decisionType,
                'decision' => $decision['decision'] === 'APPROVE_LOCAL_STAGING' ? 'APPROVE' : 'REJECT', 'reason' => $decision['reason'],
                'requested_by' => $current->requested_by, 'decided_by' => $actor->id, 'evidence_hash' => $evidenceHash,
                'step_up_evidence_reference' => $stepUpEvidenceReference, 'occurred_at' => $now,
            ]);
            TaxAuthorityOnboardingCase::where('id', $caseId)->update([
                'status' => $nextStatus, 'approved_at' => $decision['decision'] === 'APPROVE_LOCAL_STAGING' ? $now : null, 'updated_at' => $now,
            ]);
            TaxAuthorityGovernanceEvent::create([
                'id' => (string) Str::uuid(), 'tax_authority_id' => $current->tax_authority_id, 'onboarding_case_id' => $caseId,
                'event_type' => $eventType, 'from_status' => $fromStatus, 'to_status' => $nextStatus, 'reason_code' => $reasonCode,
                'evidence_hash' => $evidenceHash, 'actor_id' => $actor->id, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'DECIDE_AUTHORITY_ONBOARDING_CASE', $idempotencyKey, $requestHash, 'TAX_AUTHORITY_ONBOARDING_CASE', $caseId, $now);
            CommandLedger::outbox('TAX_AUTHORITY_ONBOARDING', $caseId, $eventType, $current->tax_authority_id, [
                'onboarding_case_id' => $caseId, 'tax_authority_id' => $current->tax_authority_id, 'status' => $nextStatus,
                'reason_code' => $reasonCode, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, $eventType, 'TAX_AUTHORITY_ONBOARDING_CASE', $caseId, [
                'authorityId' => $current->tax_authority_id, 'fromStatus' => $fromStatus, 'toStatus' => $nextStatus,
                'reasonCode' => $reasonCode, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->present($caseId, $actor);
    }

    private function requireLocalWritesEnabled(): void
    {
        if (app()->environment('production')) {
            throw new AuthorizationException('Authority onboarding writes are unavailable in production until the approved operational control plane is deployed.');
        }
    }

    private function requireAuthorityScope(User $actor, string $authorityId): void
    {
        $now = now();
        $isAdmin = TaxAuthorityAdministrator::where('tax_authority_id', $authorityId)->where('user_id', $actor->id)
            ->where('status', 'ACTIVE')->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->exists();
        if (! $isAdmin) {
            throw new AuthorizationException('The actor is not an active administrator for the requested Tax Authority.');
        }
    }

    private function requireCurrentAuthorityReview(string $authorityId): void
    {
        $today = now()->toDateString();
        $now = now();
        $hasReview = DB::table('tax_authority_access_reviews')->where('tax_authority_id', $authorityId)
            ->where('review_type', 'QUARTERLY')->whereIn('status', ['OPEN', 'COMPLETED'])
            ->whereDate('period_start', '<=', $today)->where('due_at', '>=', $now)
            ->exists();
        if (! $hasReview) {
            throw new AuthorizationException('QUARTERLY_AUTHORITY_ACCESS_REVIEW_REQUIRED: A current Tax Authority access review is required for privileged governance decisions.');
        }
    }

    /** Scoped to the actor's own admin membership -- matches the source's own onboardingCase() helper exactly (used for both idempotent-replay lookups and the post-command read). */
    private function findForActor(string $caseId, User $actor): ?TaxAuthorityOnboardingCase
    {
        $exists = DB::table('tax_authority_onboarding_cases as c')
            ->join('tax_authority_administrators as admin', function ($join) use ($actor) {
                $join->on('admin.tax_authority_id', '=', 'c.tax_authority_id')->where('admin.user_id', $actor->id)->where('admin.status', 'ACTIVE');
            })
            ->where('c.id', $caseId)->exists();

        return $exists ? TaxAuthorityOnboardingCase::find($caseId) : null;
    }

    /** @return array<string, mixed> */
    private function present(string $caseId, User $actor): array
    {
        $case = DB::table('tax_authority_onboarding_cases as c')
            ->join('tax_authorities as ta', 'ta.id', '=', 'c.tax_authority_id')
            ->join('users as requester', 'requester.id', '=', 'c.requested_by')
            ->join('tax_authority_administrators as admin', function ($join) use ($actor) {
                $join->on('admin.tax_authority_id', '=', 'c.tax_authority_id')->where('admin.user_id', $actor->id)->where('admin.status', 'ACTIVE');
            })
            ->leftJoin('tax_authority_onboarding_decisions as d', 'd.onboarding_case_id', '=', 'c.id')
            ->leftJoin('users as decider', 'decider.id', '=', 'd.decided_by')
            ->where('c.id', $caseId)
            ->orderByDesc('d.occurred_at')
            ->first(['c.id', 'c.tax_authority_id', 'ta.name as authority_name', 'c.target_environment', 'c.status', 'c.purpose',
                'c.evidence_bundle_hash', 'c.readiness_reference', 'c.requested_by', 'requester.name as requester_name',
                'c.submitted_at', 'c.approved_at', 'c.activated_at', 'c.updated_at',
                'd.decision_type', 'd.reason as decision_reason', 'decider.name as decided_by_name']);
        if (! $case) {
            throw new AuthorityGovernanceResourceException('Authority-onboarding case was not found.', 404);
        }

        return (array) $case;
    }

    /** @return list<array<string, mixed>> */
    private function rows(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn ($row) => (array) $row)->values()->all();
    }
}

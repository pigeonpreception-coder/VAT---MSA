<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AuditCase;
use App\Models\AuditCaseNote;
use App\Models\AuditCaseTransition;
use App\Models\AuditEvidence;
use App\Models\AuditEvidenceCustodyEvent;
use App\Models\AuditFinding;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Compliance\NotificationRecorder;
use App\Support\Compliance\TaxpayerResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's openAuditCase/
 * transitionCase/issueFinding/getCaseTimeline/addEvidence/
 * recordEvidenceCustodyEvent/getCaseEvidence/addCaseNote/getCaseNotes --
 * Module 4 Phases C-D, the first Phase 11 slice. `App\Support\Business\
 * CommandLedger` (the generic command_idempotency + outbox helper Phase
 * 10 built) is reused unchanged here -- compliance commands share the
 * exact same idempotency/outbox pattern.
 */
class AuditCaseService
{
    public function __construct(private readonly TaxpayerResolver $taxpayers) {}

    /** @return array<string, mixed> */
    public function open(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may open an audit case.');
        }
        $input = ComplianceValidator::caseOpening($payload);
        $scope = $this->taxpayers->resolve($actor, $input['taxpayer_id']);
        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'OPEN_AUDIT_CASE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }

        $id = (string) Str::uuid();
        $caseNumber = 'CASE-'.now()->format('Y').'-'.mb_strtoupper(mb_substr(str_replace('-', '', $id), 0, 8));
        $now = now();

        DB::transaction(function () use ($input, $scope, $actor, $id, $caseNumber, $now, $idempotencyKey, $requestHash, $correlationId) {
            AuditCase::create([
                'id' => $id, 'case_number' => $caseNumber, 'organisation_id' => $scope['organisation_id'], 'taxpayer_id' => $scope['taxpayer_id'],
                'case_type' => $input['case_type'], 'title' => $input['title'], 'opening_reason' => $input['opening_reason'], 'risk_tier' => $input['risk_tier'],
                'status' => 'PROPOSED', 'assigned_officer_id' => null, 'opened_by' => $actor->id, 'opened_at' => $now, 'updated_at' => $now, 'closed_at' => null,
            ]);
            NotificationRecorder::record(null, $scope['taxpayer_id'], 'AUDIT_CASE_OPENED', "Audit case {$caseNumber} opened", $input['title'], 'HIGH', "/cases/{$id}", $now);
            CommandLedger::record($actor->id, 'OPEN_AUDIT_CASE', $idempotencyKey, $requestHash, 'AUDIT_CASE', $id, $now);
            CommandLedger::outbox('AUDIT_CASE', $id, 'AuditCaseOpened', $scope['taxpayer_id'], ['case_id' => $id, 'case_number' => $caseNumber, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'AUDIT_CASE_OPENED', 'AUDIT_CASE', $id, ['caseNumber' => $caseNumber, 'taxpayerId' => $scope['taxpayer_id'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($id));
    }

    /**
     * The single code path that can ever change an audit case's status --
     * every action flows through here, writing one audit_case_transitions
     * row per change (never just flipping the status column in place).
     *
     * @return array<string, mixed>
     */
    public function transition(string $caseId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may transition an audit case.');
        }
        $input = ComplianceValidator::caseTransition($payload);
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            throw new ComplianceResourceException('Audit case was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['case_id' => $caseId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'TRANSITION_CASE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }

        $staticTarget = ComplianceValidator::assertCaseTransition($input['action'], $auditCase->status);
        if ($input['action'] === 'RESUME') {
            if (! $auditCase->suspended_from_status) {
                throw new ComplianceResourceException('This case has no recorded state to resume into.', 409);
            }
            $targetStatus = $auditCase->suspended_from_status;
        } else {
            $targetStatus = $staticTarget;
        }
        $nextSuspendedFrom = $input['action'] === 'SUSPEND' ? $auditCase->status : null;

        if ($input['action'] === 'ASSIGN') {
            $officer = User::find($input['officerId']);
            if (! $officer) {
                throw new ComplianceResourceException('The assigned officer does not exist.', 404);
            }
            if ($officer->status !== 'ACTIVE') {
                throw new ComplianceResourceException('The assigned officer is not active.', 409);
            }
        }
        $sodOverrideApplied = false;
        if ($input['action'] === 'CLOSE') {
            $findingCount = AuditFinding::where('audit_case_id', $caseId)->count();
            if ($findingCount === 0) {
                throw new RepositoryConflictException('A case cannot be closed with no findings on record.');
            }
            $sodOverrideApplied = $this->enforceSegregationOfDuties($actor, $auditCase->opened_by, $input['overrideReason'], 'close it');
        }

        $now = now();
        $fromStatus = $auditCase->status;
        DB::transaction(function () use ($auditCase, $caseId, $input, $targetStatus, $nextSuspendedFrom, $fromStatus, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $sodOverrideApplied) {
            AuditCase::where('id', $caseId)->update([
                'status' => $targetStatus, 'updated_at' => $now, 'suspended_from_status' => $nextSuspendedFrom,
                'assigned_officer_id' => $input['action'] === 'ASSIGN' ? $input['officerId'] : $auditCase->assigned_officer_id,
                'closed_at' => $input['action'] === 'CLOSE' ? $now : $auditCase->closed_at,
                'appeal_reference' => $input['action'] === 'LINK_APPEAL' ? $input['appealReference'] : $auditCase->appeal_reference,
                'appeal_linked_at' => $input['action'] === 'LINK_APPEAL' ? $now : $auditCase->appeal_linked_at,
            ]);
            AuditCaseTransition::create([
                'id' => (string) Str::uuid(), 'audit_case_id' => $caseId, 'action' => $input['action'], 'from_status' => $fromStatus,
                'to_status' => $targetStatus, 'actor_id' => $actor->id, 'reason' => $input['reason'], 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'TRANSITION_CASE', $idempotencyKey, $requestHash, 'AUDIT_CASE', $caseId, $now);
            CommandLedger::outbox('AUDIT_CASE', $caseId, 'AuditCase'.ucfirst(mb_strtolower(str_replace('_', '', $input['action']))), $auditCase->taxpayer_id, [
                'case_id' => $caseId, 'action' => $input['action'], 'from_status' => $fromStatus, 'to_status' => $targetStatus, 'correlation_id' => $correlationId, 'sod_override' => $sodOverrideApplied,
            ], $now);
            // A single audit_events row per command, not two: AuditService::append reads
            // "the latest hash" from the DB at call time, so calling it twice while
            // building one transaction (neither insert committed yet) would give both
            // rows the same previous_hash and break the chain's linearity. The override
            // is instead a distinctly-named action plus full detail on this one row.
            AuditService::append($actor, ($sodOverrideApplied ? "AUDIT_CASE_{$input['action']}_SOD_OVERRIDE" : "AUDIT_CASE_{$input['action']}"), 'AUDIT_CASE', $caseId, array_merge([
                'action' => $input['action'], 'fromStatus' => $fromStatus, 'toStatus' => $targetStatus, 'reason' => $input['reason'], 'correlationId' => $correlationId,
            ], $sodOverrideApplied ? ['sodOverride' => true, 'openedBy' => $auditCase->opened_by, 'overriddenBy' => $actor->id, 'overrideReason' => $input['overrideReason']] : []), $now);
        });

        return $this->present($this->findOrFail($caseId));
    }

    /** Restricted to the case's analytical/reporting stages (ANALYSIS, TAXPAYER_RESPONSE, FINDINGS_REVIEW). @return array<string, mixed> */
    public function issueFinding(string $caseId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may issue an audit finding.');
        }
        $input = ComplianceValidator::findingIssuance($payload);
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            throw new ComplianceResourceException('Audit case was not found.', 404);
        }
        if (! in_array($auditCase->status, ['ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW'], true)) {
            throw new RepositoryConflictException("Findings cannot be issued while the case is {$auditCase->status}.");
        }
        $sodOverrideApplied = $this->enforceSegregationOfDuties($actor, $auditCase->opened_by, $input['overrideReason'], 'issue a finding on it');
        $requestHash = CommandLedger::requestHash(['case_id' => $caseId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'ISSUE_FINDING', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentFinding($this->findFindingOrFail($prior));
        }
        $existing = AuditFinding::where('audit_case_id', $caseId)->where('finding_code', $input['finding_code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("A finding with this code already exists as {$existing->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $caseId, $auditCase, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId, $sodOverrideApplied) {
            AuditFinding::create([
                'id' => $id, 'audit_case_id' => $caseId, 'finding_code' => $input['finding_code'], 'title' => $input['title'],
                'description' => $input['description'], 'legal_reference' => $input['legal_reference'], 'amount_cents' => $input['amount_cents'],
                'currency' => $input['currency'], 'status' => 'PRELIMINARY', 'author_id' => $actor->id, 'created_at' => $now, 'resolved_at' => null,
            ]);
            CommandLedger::record($actor->id, 'ISSUE_FINDING', $idempotencyKey, $requestHash, 'AUDIT_FINDING', $id, $now);
            CommandLedger::outbox('AUDIT_FINDING', $id, 'AuditFindingIssued', $auditCase->taxpayer_id, ['finding_id' => $id, 'audit_case_id' => $caseId, 'correlation_id' => $correlationId, 'sod_override' => $sodOverrideApplied], $now);
            AuditService::append($actor, $sodOverrideApplied ? 'AUDIT_FINDING_ISSUED_SOD_OVERRIDE' : 'AUDIT_FINDING_ISSUED', 'AUDIT_FINDING', $id, array_merge([
                'auditCaseId' => $caseId, 'findingCode' => $input['finding_code'], 'correlationId' => $correlationId,
            ], $sodOverrideApplied ? ['sodOverride' => true, 'openedBy' => $auditCase->opened_by, 'overriddenBy' => $actor->id, 'overrideReason' => $input['overrideReason']] : []), $now);
        });

        return $this->presentFinding($this->findFindingOrFail($id));
    }

    /** The complete, chronological transition history for one case. Tenant-scoped: a taxpayer may read their own case's timeline, but only national-scope actors can read any case. @return ?array<string, mixed> */
    public function timeline(string $caseId, User $actor): ?array
    {
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            return null;
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $auditCase->taxpayer_id) {
            throw new AuthorizationException('The audit case is outside your authorised taxpayer scope.');
        }
        $transitions = AuditCaseTransition::where('audit_case_id', $caseId)->orderBy('occurred_at')->get();

        return [
            'case' => ['id' => $auditCase->id, 'taxpayer_id' => $auditCase->taxpayer_id, 'case_number' => $auditCase->case_number, 'status' => $auditCase->status],
            'transitions' => $transitions->map(fn (AuditCaseTransition $t) => [
                'action' => $t->action, 'from_status' => $t->from_status, 'to_status' => $t->to_status,
                'actor_id' => $t->actor_id, 'reason' => $t->reason, 'occurred_at' => optional($t->occurred_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    /**
     * A document citation must already be clean-scanned -- deferred in this
     * port (see docs/MIGRATION_MATRIX.md's Phase 11 note): DOCUMENT/
     * VAT_RETURN source types are not yet supported since document_metadata
     * and vat_return_versions haven't been migrated. INVOICE and OTHER are
     * fully supported. A supersedes_evidence_id flips the prior row to
     * SUPERSEDED before the new row is inserted.
     *
     * @return array<string, mixed>
     */
    public function addEvidence(string $caseId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may add case evidence.');
        }
        $input = ComplianceValidator::evidenceAddition($payload);
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            throw new ComplianceResourceException('Audit case was not found.', 404);
        }
        if ($auditCase->status === 'CANCELLED') {
            throw new RepositoryConflictException('Evidence cannot be added to a cancelled case.');
        }
        $requestHash = CommandLedger::requestHash(['case_id' => $caseId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'ADD_EVIDENCE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentEvidence($this->findEvidenceOrFail($prior));
        }

        if (in_array($input['sourceResourceType'], ['VAT_RETURN', 'DOCUMENT'], true)) {
            throw new ComplianceResourceException("Evidence sourced from {$input['sourceResourceType']} is not yet supported by this migration -- its underlying table has not been ported. Cite an INVOICE or an OTHER (externally hashed) record instead.", 422);
        }

        $checksum = null;
        $evidenceType = null;
        if ($input['sourceResourceType'] === 'OTHER') {
            $checksum = $input['checksumSha256'];
            $evidenceType = 'EXTERNAL_RECORD';
        } else {
            $invoice = Invoice::find($input['sourceResourceId']);
            if (! $invoice) {
                throw new ComplianceResourceException('The cited invoice was not found.', 404);
            }
            $checksum = $invoice->payload_hash;
            $evidenceType = 'CERTIFIED_RECORD';
        }

        if ($input['supersedesEvidenceId']) {
            $superseded = AuditEvidence::where('id', $input['supersedesEvidenceId'])->where('audit_case_id', $caseId)->first();
            if (! $superseded) {
                throw new ComplianceResourceException('The evidence being superseded was not found on this case.', 404);
            }
            if ($superseded->status !== 'PRESERVED') {
                throw new RepositoryConflictException('Only currently preserved evidence can be superseded.');
            }
        } else {
            $activeCitation = AuditEvidence::where('audit_case_id', $caseId)->where('source_resource_type', $input['sourceResourceType'])
                ->where('source_resource_id', $input['sourceResourceId'])->where('status', 'PRESERVED')->first();
            if ($activeCitation) {
                throw new RepositoryConflictException("This source is already cited as active evidence ({$activeCitation->id}) -- supersede it instead of adding a duplicate.");
            }
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $caseId, $auditCase, $checksum, $evidenceType, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            if ($input['supersedesEvidenceId']) {
                AuditEvidence::where('id', $input['supersedesEvidenceId'])->update(['status' => 'SUPERSEDED']);
                AuditEvidenceCustodyEvent::create([
                    'id' => (string) Str::uuid(), 'audit_evidence_id' => $input['supersedesEvidenceId'], 'action' => 'SUPERSEDED',
                    'actor_id' => $actor->id, 'notes' => "Superseded by evidence {$id}.", 'integrity_verified' => null, 'occurred_at' => $now,
                ]);
            }
            AuditEvidence::create([
                'id' => $id, 'audit_case_id' => $caseId, 'evidence_type' => $evidenceType, 'source_resource_type' => $input['sourceResourceType'],
                'source_resource_id' => $input['sourceResourceId'], 'document_id' => null, 'checksum_sha256' => $checksum, 'description' => $input['description'],
                'status' => 'PRESERVED', 'added_by' => $actor->id, 'added_at' => $now, 'previous_version_id' => $input['supersedesEvidenceId'], 'legal_hold' => false,
            ]);
            AuditEvidenceCustodyEvent::create([
                'id' => (string) Str::uuid(), 'audit_evidence_id' => $id, 'action' => 'ADDED', 'actor_id' => $actor->id,
                'notes' => $input['description'], 'integrity_verified' => null, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'ADD_EVIDENCE', $idempotencyKey, $requestHash, 'AUDIT_EVIDENCE', $id, $now);
            CommandLedger::outbox('AUDIT_EVIDENCE', $id, 'AuditEvidenceAdded', $auditCase->taxpayer_id, ['evidence_id' => $id, 'audit_case_id' => $caseId, 'source_resource_type' => $input['sourceResourceType'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'AUDIT_EVIDENCE_ADDED', 'AUDIT_EVIDENCE', $id, ['auditCaseId' => $caseId, 'sourceResourceType' => $input['sourceResourceType'], 'sourceResourceId' => $input['sourceResourceId'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentEvidence($this->findEvidenceOrFail($id));
    }

    /**
     * VERIFY re-derives the cited record's CURRENT hash and compares it
     * against the hash stored at addition time -- a genuine tamper/drift
     * detector. A mismatch is recorded, not thrown: this is an audit trail
     * feeding human judgement, not an automated adverse action.
     *
     * @return array<string, mixed>
     */
    public function recordEvidenceCustodyEvent(string $evidenceId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may record an evidence custody event.');
        }
        $input = ComplianceValidator::evidenceCustodyEvent($payload);
        $evidence = AuditEvidence::with('auditCase')->find($evidenceId);
        if (! $evidence) {
            throw new ComplianceResourceException('Evidence record was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['evidence_id' => $evidenceId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RECORD_EVIDENCE_CUSTODY_EVENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentEvidence($this->findEvidenceOrFail($prior));
        }

        $now = now();
        $integrityVerified = null;
        DB::transaction(function () use ($evidence, $evidenceId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId, &$integrityVerified) {
            if ($input['action'] === 'VERIFY') {
                if ($evidence->source_resource_type === 'INVOICE') {
                    $invoice = Invoice::find($evidence->source_resource_id);
                    $integrityVerified = $invoice && $invoice->payload_hash === $evidence->checksum_sha256;
                }
                // OTHER (externally supplied) evidence has nothing this system can
                // re-derive, so integrity_verified stays null rather than a false claim.
            } elseif ($input['action'] === 'SET_LEGAL_HOLD') {
                AuditEvidence::where('id', $evidenceId)->update(['legal_hold' => true]);
            } else {
                AuditEvidence::where('id', $evidenceId)->update(['legal_hold' => false]);
            }
            AuditEvidenceCustodyEvent::create([
                'id' => (string) Str::uuid(), 'audit_evidence_id' => $evidenceId, 'action' => $input['action'], 'actor_id' => $actor->id,
                'notes' => $input['notes'], 'integrity_verified' => $integrityVerified, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RECORD_EVIDENCE_CUSTODY_EVENT', $idempotencyKey, $requestHash, 'AUDIT_EVIDENCE', $evidenceId, $now);
            CommandLedger::outbox('AUDIT_EVIDENCE', $evidenceId, 'AuditEvidence'.ucfirst(mb_strtolower(str_replace('_', '', $input['action']))), $evidence->auditCase->taxpayer_id, [
                'evidence_id' => $evidenceId, 'action' => $input['action'], 'integrity_verified' => $integrityVerified, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, "AUDIT_EVIDENCE_{$input['action']}", 'AUDIT_EVIDENCE', $evidenceId, ['action' => $input['action'], 'integrityVerified' => $integrityVerified, 'notes' => $input['notes'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentEvidence($this->findEvidenceOrFail($evidenceId));
    }

    /** Tenant-scoped exactly like timeline(): national-scope or the case's own taxpayer. @return ?array<string, mixed> */
    public function evidence(string $caseId, User $actor): ?array
    {
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            return null;
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $auditCase->taxpayer_id) {
            throw new AuthorizationException('The audit case is outside your authorised taxpayer scope.');
        }
        $evidence = AuditEvidence::where('audit_case_id', $caseId)->orderBy('added_at')->get();
        $evidenceIds = $evidence->pluck('id')->all();
        $custodyEvents = count($evidenceIds) > 0
            ? AuditEvidenceCustodyEvent::whereIn('audit_evidence_id', $evidenceIds)->orderBy('occurred_at')->get()
            : collect();

        return [
            'case' => ['id' => $auditCase->id, 'taxpayer_id' => $auditCase->taxpayer_id],
            'evidence' => $evidence->map(fn (AuditEvidence $e) => $this->presentEvidence($e))->values()->all(),
            'custodyEvents' => $custodyEvents->map(fn (AuditEvidenceCustodyEvent $c) => [
                'id' => $c->id, 'audit_evidence_id' => $c->audit_evidence_id, 'action' => $c->action, 'actor_id' => $c->actor_id,
                'notes' => $c->notes, 'integrity_verified' => $c->integrity_verified, 'occurred_at' => optional($c->occurred_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    /** Notes are only ever inserted -- a correction is a fresh note carrying supersedes_note_id, the note it corrects remains exactly as originally written. @return array<string, mixed> */
    public function addNote(string $caseId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may add a case note.');
        }
        $input = ComplianceValidator::caseNoteAddition($payload);
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            throw new ComplianceResourceException('Audit case was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['case_id' => $caseId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'ADD_CASE_NOTE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentNote($this->findNoteOrFail($prior));
        }
        if ($input['supersedesNoteId']) {
            $supersededNote = AuditCaseNote::where('id', $input['supersedesNoteId'])->where('audit_case_id', $caseId)->first();
            if (! $supersededNote) {
                throw new ComplianceResourceException('The note being corrected was not found on this case.', 404);
            }
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $caseId, $auditCase, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            AuditCaseNote::create([
                'id' => $id, 'audit_case_id' => $caseId, 'author_id' => $actor->id, 'body' => $input['body'],
                'supersedes_note_id' => $input['supersedesNoteId'], 'created_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'ADD_CASE_NOTE', $idempotencyKey, $requestHash, 'AUDIT_CASE_NOTE', $id, $now);
            CommandLedger::outbox('AUDIT_CASE_NOTE', $id, 'AuditCaseNoteAdded', $auditCase->taxpayer_id, ['note_id' => $id, 'audit_case_id' => $caseId, 'supersedes_note_id' => $input['supersedesNoteId'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'AUDIT_CASE_NOTE_ADDED', 'AUDIT_CASE_NOTE', $id, ['auditCaseId' => $caseId, 'supersedesNoteId' => $input['supersedesNoteId'], 'correlationId' => $correlationId], $now);
        });

        return $this->presentNote($this->findNoteOrFail($id));
    }

    /** Tenant-scoped exactly like timeline()/evidence(). @return ?array<string, mixed> */
    public function notes(string $caseId, User $actor): ?array
    {
        $auditCase = AuditCase::find($caseId);
        if (! $auditCase) {
            return null;
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $auditCase->taxpayer_id) {
            throw new AuthorizationException('The audit case is outside your authorised taxpayer scope.');
        }
        $notes = AuditCaseNote::where('audit_case_id', $caseId)->orderBy('created_at')->get();

        return [
            'case' => ['id' => $auditCase->id, 'taxpayer_id' => $auditCase->taxpayer_id],
            'notes' => $notes->map(fn (AuditCaseNote $n) => $this->presentNote($n))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function search(User $actor, array $params): array
    {
        $query = AuditCase::query();
        if (! TenantScope::isNational($actor)) {
            $query->where('taxpayer_id', $actor->taxpayer_id ?? '__none__');
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        $cases = $query->orderByDesc('updated_at')->limit(100)->get();

        return ['cases' => $cases->map(fn (AuditCase $c) => $this->present($c))->values()->all()];
    }

    // -- internals --

    /**
     * Segregation of duties: the officer who opened a case may not also
     * close it or issue a finding on it. Returns whether an exceptional
     * override was applied, so the caller can log a distinct, clearly
     * findable audit event for it -- never a silent bypass.
     */
    private function enforceSegregationOfDuties(User $actor, string $openedBy, ?string $overrideReason, string $actionDescription): bool
    {
        if ($openedBy !== $actor->id) {
            return false;
        }
        if (! $overrideReason) {
            throw new AuthorizationException("Segregation of duties: the officer who opened this case cannot also {$actionDescription}. An authorised supervisor may override with cases:override-sod and a recorded reason.");
        }
        if (! $actor->hasAppPermission('cases:override-sod')) {
            throw new AuthorizationException("Overriding this segregation-of-duties control to {$actionDescription} requires cases:override-sod.");
        }

        return true;
    }

    private function findOrFail(string $id): AuditCase
    {
        $case = AuditCase::find($id);
        if (! $case) {
            throw new ComplianceResourceException('Audit case was not found.', 404);
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private function present(AuditCase $case): array
    {
        return [
            'id' => $case->id, 'case_number' => $case->case_number, 'organisation_id' => $case->organisation_id, 'taxpayer_id' => $case->taxpayer_id,
            'case_type' => $case->case_type, 'title' => $case->title, 'opening_reason' => $case->opening_reason, 'risk_tier' => $case->risk_tier,
            'status' => $case->status, 'assigned_officer_id' => $case->assigned_officer_id, 'opened_by' => $case->opened_by,
            'opened_at' => optional($case->opened_at)->toISOString(), 'updated_at' => optional($case->updated_at)->toISOString(),
            'closed_at' => optional($case->closed_at)->toISOString(), 'suspended_from_status' => $case->suspended_from_status,
            'appeal_reference' => $case->appeal_reference, 'appeal_linked_at' => optional($case->appeal_linked_at)->toISOString(),
        ];
    }

    private function findFindingOrFail(string $id): AuditFinding
    {
        $finding = AuditFinding::find($id);
        if (! $finding) {
            throw new ComplianceResourceException('Audit finding was not found.', 404);
        }

        return $finding;
    }

    /** @return array<string, mixed> */
    private function presentFinding(AuditFinding $finding): array
    {
        return [
            'id' => $finding->id, 'audit_case_id' => $finding->audit_case_id, 'finding_code' => $finding->finding_code, 'title' => $finding->title,
            'description' => $finding->description, 'legal_reference' => $finding->legal_reference, 'amount_cents' => (int) $finding->amount_cents,
            'currency' => $finding->currency, 'status' => $finding->status, 'author_id' => $finding->author_id,
            'created_at' => optional($finding->created_at)->toISOString(), 'resolved_at' => optional($finding->resolved_at)->toISOString(),
        ];
    }

    private function findEvidenceOrFail(string $id): AuditEvidence
    {
        $evidence = AuditEvidence::find($id);
        if (! $evidence) {
            throw new ComplianceResourceException('Evidence record was not found.', 404);
        }

        return $evidence;
    }

    /** @return array<string, mixed> */
    private function presentEvidence(AuditEvidence $evidence): array
    {
        return [
            'id' => $evidence->id, 'audit_case_id' => $evidence->audit_case_id, 'evidence_type' => $evidence->evidence_type,
            'source_resource_type' => $evidence->source_resource_type, 'source_resource_id' => $evidence->source_resource_id,
            'document_id' => $evidence->document_id, 'checksum_sha256' => $evidence->checksum_sha256, 'description' => $evidence->description,
            'status' => $evidence->status, 'added_by' => $evidence->added_by, 'added_at' => optional($evidence->added_at)->toISOString(),
            'previous_version_id' => $evidence->previous_version_id, 'legal_hold' => (bool) $evidence->legal_hold,
        ];
    }

    private function findNoteOrFail(string $id): AuditCaseNote
    {
        $note = AuditCaseNote::find($id);
        if (! $note) {
            throw new ComplianceResourceException('Case note was not found.', 404);
        }

        return $note;
    }

    /** @return array<string, mixed> */
    private function presentNote(AuditCaseNote $note): array
    {
        return [
            'id' => $note->id, 'audit_case_id' => $note->audit_case_id, 'author_id' => $note->author_id, 'body' => $note->body,
            'supersedes_note_id' => $note->supersedes_note_id, 'created_at' => optional($note->created_at)->toISOString(),
        ];
    }
}

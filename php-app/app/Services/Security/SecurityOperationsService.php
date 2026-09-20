<?php

namespace App\Services\Security;

use App\Domain\Security\SecurityValidator;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SecurityResourceException;
use App\Models\IdentityLink;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityPlaybookAction;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/security-repository.ts -- Module 8 Phase B's
 * Security Operations Centre queue: the manual/human half of incident
 * handling that sits alongside App\Support\Security\SecurityEventRecorder's
 * own automated detection-rule side (the rule-fired path this class's
 * getSOCQueue/getIncidentDetail reads share the same security_incidents/
 * security_playbook_actions tables SecurityEventRecorder already writes
 * to). revokeIncidentAccess reuses Module 1's own session-revocation
 * mechanism (App\Models\IdentityLink's ACTIVE->REVOKED transition) rather
 * than duplicating it, exactly matching the source's own comment.
 */
class SecurityOperationsService
{
    /** @return array<int, array<string, mixed>> */
    public function getSOCQueue(?string $status, ?string $severity): array
    {
        $query = SecurityIncident::with('detectionRule');
        if ($status) {
            $query->where('status', $status);
        }
        if ($severity) {
            $query->where('severity', $severity);
        }

        return $query->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END")
            ->orderByDesc('opened_at')->limit(200)->get()
            ->map(fn (SecurityIncident $incident) => $this->presentIncident($incident))->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function getRecentEvents(int $limit = 50): array
    {
        return SecurityEvent::orderByDesc('occurred_at')->limit($limit)->get()->map(fn (SecurityEvent $event) => [
            'id' => $event->id, 'event_type' => $event->event_type, 'severity' => $event->severity,
            'actor_id' => $event->actor_id, 'source_token' => $event->source_token, 'correlation_id' => $event->correlation_id,
            'action' => $event->action, 'outcome' => $event->outcome, 'occurred_at' => $event->occurred_at,
        ])->all();
    }

    /** @return array{incident: array<string, mixed>, actions: array<int, array<string, mixed>>} */
    public function getIncidentDetail(string $incidentId): array
    {
        $incident = SecurityIncident::with('detectionRule')->find($incidentId);
        if (! $incident) {
            throw new SecurityResourceException('Security incident was not found.', 404);
        }
        $actions = SecurityPlaybookAction::where('incident_id', $incidentId)->orderBy('performed_at')->get()
            ->map(fn (SecurityPlaybookAction $action) => [
                'id' => $action->id, 'action_type' => $action->action_type, 'actor_id' => $action->actor_id,
                'automated' => (bool) $action->automated, 'details' => $action->details, 'performed_at' => $action->performed_at,
            ])->all();

        return ['incident' => $this->presentIncident($incident), 'actions' => $actions];
    }

    /** @return array{incident: array<string, mixed>, actions: array<int, array<string, mixed>>} */
    public function createIncident(User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SecurityValidator::incidentCreate($payload);

        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'CREATE_SECURITY_INCIDENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->getIncidentDetail($prior);
        }
        if ($input['source_event_id'] && ! SecurityEvent::where('id', $input['source_event_id'])->exists()) {
            throw new SecurityResourceException('The referenced security event was not found.', 404);
        }
        if ($input['subject_user_id'] && ! User::where('id', $input['subject_user_id'])->exists()) {
            throw new SecurityResourceException('The referenced subject user was not found.', 404);
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            SecurityIncident::create([
                'id' => $id, 'title' => $input['title'], 'severity' => $input['severity'], 'status' => 'OPEN',
                'source_event_id' => $input['source_event_id'], 'automated_action' => null, 'owner' => null,
                'detection_rule_id' => null, 'group_key' => null, 'subject_user_id' => $input['subject_user_id'],
                'opened_at' => $now, 'updated_at' => $now, 'closed_at' => null, 'closed_by' => null, 'resolution_notes' => null,
            ]);
            SecurityPlaybookAction::create([
                'id' => (string) Str::uuid(), 'incident_id' => $id, 'action_type' => 'OPENED', 'actor_id' => $actor->id,
                'automated' => false, 'details' => AuditService::canonicalJson(['details' => $input['details']]), 'performed_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_SECURITY_INCIDENT', $idempotencyKey, $requestHash, 'SECURITY_INCIDENT', $id, $now);
            CommandLedger::outbox('SECURITY_INCIDENT', $id, 'SecurityIncidentOpened', $id, [
                'incident_id' => $id, 'severity' => $input['severity'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SECURITY_INCIDENT_OPENED', 'SECURITY_INCIDENT', $id, [
                'title' => $input['title'], 'severity' => $input['severity'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->getIncidentDetail($id);
    }

    /** Module 8 Phase B Contain: OPEN to CONTAINED triage bookkeeping -- no technical side effect of its own (see revokeAccess for the real access-cutting action). */
    public function containIncident(string $incidentId, User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SecurityValidator::incidentAction($payload);

        $requestHash = CommandLedger::requestHash(['incident_id' => $incidentId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CONTAIN_SECURITY_INCIDENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->getIncidentDetail($incidentId);
        }
        $incident = SecurityIncident::find($incidentId);
        if (! $incident) {
            throw new SecurityResourceException('Security incident was not found.', 404);
        }
        if ($incident->status !== 'OPEN') {
            throw new RepositoryConflictException('Only an open incident can be contained.');
        }

        $now = now();
        DB::transaction(function () use ($incident, $incidentId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            SecurityIncident::where('id', $incidentId)->where('status', 'OPEN')
                ->update(['status' => 'CONTAINED', 'owner' => $incident->owner ?? $actor->id, 'updated_at' => $now]);
            SecurityPlaybookAction::create([
                'id' => (string) Str::uuid(), 'incident_id' => $incidentId, 'action_type' => 'CONTAIN', 'actor_id' => $actor->id,
                'automated' => false, 'details' => AuditService::canonicalJson(['notes' => $input['notes']]), 'performed_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CONTAIN_SECURITY_INCIDENT', $idempotencyKey, $requestHash, 'SECURITY_INCIDENT', $incidentId, $now);
            CommandLedger::outbox('SECURITY_INCIDENT', $incidentId, 'SecurityIncidentContained', $incidentId, [
                'incident_id' => $incidentId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SECURITY_INCIDENT_CONTAINED', 'SECURITY_INCIDENT', $incidentId, [
                'notes' => $input['notes'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->getIncidentDetail($incidentId);
    }

    /**
     * Module 8 Phase B Revoke: the real technical containment action --
     * revokes every ACTIVE identity_links row for the incident's
     * subject_user_id, reusing Module 1's own session-revocation
     * mechanism rather than duplicating it. Independently callable on an
     * OPEN or CONTAINED incident, and itself advances OPEN to CONTAINED
     * if the incident hadn't been triaged yet.
     */
    public function revokeIncidentAccess(string $incidentId, User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SecurityValidator::incidentAction($payload);

        $requestHash = CommandLedger::requestHash(['incident_id' => $incidentId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REVOKE_SECURITY_INCIDENT_ACCESS', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->getIncidentDetail($incidentId);
        }
        $incident = SecurityIncident::find($incidentId);
        if (! $incident) {
            throw new SecurityResourceException('Security incident was not found.', 404);
        }
        if ($incident->status === 'CLOSED') {
            throw new RepositoryConflictException('A closed incident cannot have access revoked.');
        }
        if (! $incident->subject_user_id) {
            throw new SecurityResourceException('This incident has no associated subject user to revoke access for.');
        }

        $now = now();
        $revokedCount = DB::transaction(function () use ($incident, $incidentId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $links = IdentityLink::where('user_id', $incident->subject_user_id)->where('status', 'ACTIVE')->get();
            IdentityLink::where('user_id', $incident->subject_user_id)->where('status', 'ACTIVE')->update(['status' => 'REVOKED']);
            SecurityIncident::where('id', $incidentId)
                ->update(['status' => DB::raw("CASE WHEN status = 'OPEN' THEN 'CONTAINED' ELSE status END"), 'owner' => $incident->owner ?? $actor->id, 'updated_at' => $now]);
            SecurityPlaybookAction::create([
                'id' => (string) Str::uuid(), 'incident_id' => $incidentId, 'action_type' => 'REVOKE', 'actor_id' => $actor->id,
                'automated' => false, 'details' => AuditService::canonicalJson([
                    'notes' => $input['notes'], 'revokedIdentityLinks' => $links->count(), 'subjectUserId' => $incident->subject_user_id,
                ]), 'performed_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'REVOKE_SECURITY_INCIDENT_ACCESS', $idempotencyKey, $requestHash, 'SECURITY_INCIDENT', $incidentId, $now);
            CommandLedger::outbox('SECURITY_INCIDENT', $incidentId, 'SecurityIncidentAccessRevoked', $incidentId, [
                'incident_id' => $incidentId, 'subject_user_id' => $incident->subject_user_id,
                'revoked_identity_links' => $links->count(), 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SECURITY_INCIDENT_ACCESS_REVOKED', 'SECURITY_INCIDENT', $incidentId, [
                'notes' => $input['notes'], 'subjectUserId' => $incident->subject_user_id,
                'revokedIdentityLinks' => $links->count(), 'correlationId' => $correlationId,
            ], $now);

            return $links->count();
        });
        unset($revokedCount);

        return $this->getIncidentDetail($incidentId);
    }

    /** Module 8 Phase B Close: terminal -- reachable directly from OPEN (a false positive) or from CONTAINED. */
    public function closeIncident(string $incidentId, User $actor, array $payload, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = SecurityValidator::incidentClosure($payload);

        $requestHash = CommandLedger::requestHash(['incident_id' => $incidentId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CLOSE_SECURITY_INCIDENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->getIncidentDetail($incidentId);
        }
        $incident = SecurityIncident::find($incidentId);
        if (! $incident) {
            throw new SecurityResourceException('Security incident was not found.', 404);
        }
        if ($incident->status === 'CLOSED') {
            throw new RepositoryConflictException('This incident is already closed.');
        }

        $now = now();
        DB::transaction(function () use ($incidentId, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            SecurityIncident::where('id', $incidentId)->update([
                'status' => 'CLOSED', 'closed_by' => $actor->id, 'closed_at' => $now,
                'resolution_notes' => $input['resolution_notes'], 'updated_at' => $now,
            ]);
            SecurityPlaybookAction::create([
                'id' => (string) Str::uuid(), 'incident_id' => $incidentId, 'action_type' => 'CLOSE', 'actor_id' => $actor->id,
                'automated' => false, 'details' => AuditService::canonicalJson(['resolutionNotes' => $input['resolution_notes']]), 'performed_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CLOSE_SECURITY_INCIDENT', $idempotencyKey, $requestHash, 'SECURITY_INCIDENT', $incidentId, $now);
            CommandLedger::outbox('SECURITY_INCIDENT', $incidentId, 'SecurityIncidentClosed', $incidentId, [
                'incident_id' => $incidentId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SECURITY_INCIDENT_CLOSED', 'SECURITY_INCIDENT', $incidentId, [
                'resolutionNotes' => $input['resolution_notes'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->getIncidentDetail($incidentId);
    }

    /** @return array<string, mixed> */
    private function presentIncident(SecurityIncident $incident): array
    {
        return [
            'id' => $incident->id, 'title' => $incident->title, 'severity' => $incident->severity, 'status' => $incident->status,
            'source_event_id' => $incident->source_event_id, 'automated_action' => $incident->automated_action, 'owner' => $incident->owner,
            'detection_rule_code' => $incident->detectionRule?->code, 'group_key' => $incident->group_key,
            'subject_user_id' => $incident->subject_user_id, 'opened_at' => $incident->opened_at, 'updated_at' => $incident->updated_at,
            'closed_at' => $incident->closed_at, 'closed_by' => $incident->closed_by, 'resolution_notes' => $incident->resolution_notes,
        ];
    }
}

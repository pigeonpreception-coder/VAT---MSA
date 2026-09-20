<?php

namespace App\Support\Security;

use App\Exceptions\RateLimitExceededException;
use App\Models\SecurityDetectionRule;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityPlaybookAction;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/security/request.ts's recordSecurityEvent/
 * evaluateDetectionRules/recordAuthorizationDenial/recordRateLimitBreach.
 * Never ported to this migration until now, alongside RateLimitGuard --
 * the security_events/security_detection_rules/security_incidents/
 * security_playbook_actions tables already existed (this session's own
 * "deep security sweep" audit found the schema real but unconsumed),
 * this class is what actually reads and writes them.
 *
 * Every public method here is deliberately best-effort: a security-
 * telemetry failure must never mask the real response the request was
 * already going to get, matching every call site's own `.catch(() =>
 * undefined)` in the source.
 */
class SecurityEventRecorder
{
    /** @param array<string, string|int|bool|null> $details */
    public static function record(
        string $eventType,
        string $severity,
        ?string $actorId,
        string $sourceToken,
        string $correlationId,
        string $action,
        string $outcome,
        array $details,
    ): void {
        try {
            $now = now();
            $eventId = (string) Str::uuid();
            SecurityEvent::create([
                'id' => $eventId, 'event_type' => $eventType, 'severity' => $severity, 'actor_id' => $actorId,
                'source_token' => $sourceToken, 'correlation_id' => $correlationId, 'action' => $action,
                'outcome' => $outcome, 'details' => AuditService::canonicalJson($details), 'occurred_at' => $now,
            ]);
            self::evaluateDetectionRules($eventId, $eventType, $actorId, $sourceToken, $now);
        } catch (\Throwable) {
            // Best-effort, matching every source call site's own catch(() => undefined).
        }
    }

    public static function recordAuthorizationDenial(?string $actorId, string $sourceToken, string $correlationId, string $permission, int $status): void
    {
        self::record('AUTHORISATION_DENIED', 'HIGH', $actorId, $sourceToken, $correlationId, $permission, 'DENIED', ['status' => $status]);
    }

    public static function recordRateLimitBreach(?string $actorId, string $sourceToken, string $correlationId, RateLimitExceededException $exception, string $action = 'RATE_LIMIT'): void
    {
        self::record($exception->code(), $exception->status() === 429 ? 'MEDIUM' : 'LOW', $actorId, $sourceToken, $correlationId, $action, 'REJECTED', ['status' => $exception->status()]);
    }

    /**
     * Ported from evaluateDetectionRules -- a small, fixed, code-versioned
     * rule catalogue (`security_detection_rules`, seed-only, see
     * database/seeders/SecurityDetectionRuleSeeder), evaluated inline on
     * every security event rather than by a polling job, matching this
     * codebase's established "no queue/cron infrastructure in this
     * deployment" posture (see outbox_events' own migration comment). A
     * rule fires once its event_type/group_by count reaches
     * threshold_count within window_minutes, de-duplicated against any
     * already-open incident for the same rule+group so repeated denials
     * from the same actor don't spawn a new incident on every event past
     * the threshold.
     */
    private static function evaluateDetectionRules(string $eventId, string $eventType, ?string $actorId, string $sourceToken, Carbon $now): void
    {
        $rules = SecurityDetectionRule::where('event_type', $eventType)->where('status', 'ACTIVE')->get();
        foreach ($rules as $rule) {
            $groupKey = $rule->group_by === 'actor_id' ? $actorId : $sourceToken;
            if (! $groupKey) {
                continue;
            }
            $column = $rule->group_by === 'actor_id' ? 'actor_id' : 'source_token';
            $windowStart = $now->clone()->subMinutes((int) $rule->window_minutes);
            $count = SecurityEvent::where('event_type', $eventType)->where($column, $groupKey)->where('occurred_at', '>=', $windowStart)->count();
            if ($count < $rule->threshold_count) {
                continue;
            }
            $alreadyOpen = SecurityIncident::where('detection_rule_id', $rule->id)->where('group_key', $groupKey)
                ->whereIn('status', ['OPEN', 'CONTAINED'])->exists();
            if ($alreadyOpen) {
                continue;
            }

            $incidentId = (string) Str::uuid();
            $subjectUserId = $rule->group_by === 'actor_id' ? $groupKey : null;
            DB::transaction(function () use ($incidentId, $rule, $eventId, $groupKey, $subjectUserId, $now) {
                SecurityIncident::create([
                    'id' => $incidentId, 'title' => "{$rule->code} threshold exceeded for {$groupKey}", 'severity' => $rule->severity,
                    'status' => 'OPEN', 'source_event_id' => $eventId, 'automated_action' => "AUTO_OPENED_BY_{$rule->code}",
                    'owner' => null, 'detection_rule_id' => $rule->id, 'group_key' => $groupKey, 'subject_user_id' => $subjectUserId,
                    'opened_at' => $now, 'updated_at' => $now, 'closed_at' => null, 'closed_by' => null, 'resolution_notes' => null,
                ]);
                SecurityPlaybookAction::create([
                    'id' => (string) Str::uuid(), 'incident_id' => $incidentId, 'action_type' => 'DETECTED', 'actor_id' => null,
                    'automated' => true, 'details' => AuditService::canonicalJson([
                        'ruleCode' => $rule->code, 'groupBy' => $rule->group_by, 'groupKey' => $groupKey,
                        'thresholdCount' => (int) $rule->threshold_count, 'windowMinutes' => (int) $rule->window_minutes,
                    ]), 'performed_at' => $now,
                ]);
                CommandLedger::outbox('SECURITY_INCIDENT', $incidentId, 'SecurityIncidentDetected', $groupKey, [
                    'incident_id' => $incidentId, 'rule_code' => $rule->code, 'severity' => $rule->severity,
                ], $now);
            });
        }
    }
}

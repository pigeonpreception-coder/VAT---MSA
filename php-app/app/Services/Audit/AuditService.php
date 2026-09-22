<?php

namespace App\Services\Audit;

use App\Models\AuditChainVerification;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Security\SecurityEventRecorder;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/audit-repository.ts's appendAuditEvent -- the single
 * canonical hash-chained audit-event writer. Every write anywhere in this
 * application must go through this class, never insert into audit_events
 * directly, or the chain's linearity breaks.
 *
 * Hash formula kept identical to the source: sha256(previous_hash|id|actor_id|body|occurred_at),
 * genesis fallback "GENESIS", outcome always "SUCCESS". `body` is a
 * canonical (sorted-key) JSON encoding of `details` -- computed once here
 * and stored verbatim, so a later chain-verification pass re-hashes the
 * exact stored text rather than re-deriving it from a decoded PHP array
 * (whose key order Eloquent's own casting could otherwise silently change).
 *
 * SECURITY_GAP_ASSESSMENT.md item #9 (in the source): the predecessor
 * lookup must order by true insertion order, not a caller-computed
 * timestamp that two concurrent writes could tie or invert -- the source
 * used SQLite's implicit monotonic `rowid` for this. This schema uses UUID
 * primary keys instead (see the identity-core migration's own design-
 * decision note), so `Str::orderedUuid()` is used here specifically
 * (timestamp-prefixed, lexicographically sortable) rather than the default
 * random `HasUuids` trait behaviour every other model uses -- ordering by
 * `id DESC` is then genuinely equivalent to `rowid DESC`. The same narrower
 * true-concurrent-write race the source documents (two requests' lookups
 * both running before either commits) is unchanged here; not solved by
 * this class either.
 */
class AuditService
{
    public static function append(User $actor, string $action, string $resourceType, string $resourceId, array $details, ?\DateTimeInterface $occurredAt = null): AuditEvent
    {
        return self::write($actor->id, $actor->role, $action, $resourceType, $resourceId, $details, $occurredAt);
    }

    /**
     * Ported from lib/data/signup-repository.ts's submitSelfServeSignup,
     * which writes its own audit_events row inline with a synthetic
     * `self-serve:${hash}` actorId rather than calling the source's own
     * shared appendAuditEvent -- the one command in this codebase with no
     * real, authenticated User at all. This port keeps the same one-writer
     * rule this class's own doc comment states ("never insert into
     * audit_events directly") by adding this second, explicit entry point
     * instead of also writing inline: same hash-chain, an actor identity
     * the caller supplies directly rather than a User model. $actorId is
     * shaped as a UUID (unlike source's `self-serve:`-prefixed string) to
     * fit this column's UUID type consistently with every other actor_id
     * in this table.
     */
    public static function appendSynthetic(string $actorId, string $actorRole, string $action, string $resourceType, string $resourceId, array $details, ?\DateTimeInterface $occurredAt = null): AuditEvent
    {
        return self::write($actorId, $actorRole, $action, $resourceType, $resourceId, $details, $occurredAt);
    }

    private static function write(string $actorId, string $actorRole, string $action, string $resourceType, string $resourceId, array $details, ?\DateTimeInterface $occurredAt): AuditEvent
    {
        $occurredAt ??= now();
        $id = (string) Str::orderedUuid();

        $prior = AuditEvent::orderByDesc('id')->first();
        $body = self::canonicalJson($details);
        $hash = hash('sha256', ($prior?->event_hash ?? 'GENESIS')."|{$id}|{$actorId}|{$body}|".self::isoMicro($occurredAt));

        return AuditEvent::create([
            'id' => $id,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'outcome' => 'SUCCESS',
            'details' => $body,
            'previous_hash' => $prior?->event_hash,
            'event_hash' => $hash,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return array<string, mixed> */
    public static function decodeDetails(AuditEvent $event): array
    {
        return json_decode($event->details, true) ?? [];
    }

    /**
     * Ported from lib/data/audit-repository.ts's searchAuditTrail --
     * Module 8 Phase D GetAuditTrail: a filterable, paginated, restricted
     * read, the API counterpart to the simpler unfiltered top-N read
     * App\Services\Dashboard\DashboardSnapshotService already does for
     * its own recent-activity widget.
     *
     * @param  array{resource_type?: ?string, resource_id?: ?string, action?: ?string, actor_id?: ?string, limit?: ?int, offset?: ?int}  $filter
     * @return array{items: \Illuminate\Support\Collection<int, AuditEvent>, total_count: int, limit: int, offset: int}
     */
    public static function searchTrail(array $filter): array
    {
        $query = AuditEvent::query();
        foreach (['resource_type', 'resource_id', 'action', 'actor_id'] as $column) {
            if (! empty($filter[$column])) {
                $query->where($column, $filter[$column]);
            }
        }
        $limit = min(max((int) ($filter['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filter['offset'] ?? 0), 0);
        $totalCount = (clone $query)->count();
        $items = $query->orderByDesc('occurred_at')->limit($limit)->offset($offset)->get();

        return ['items' => $items, 'total_count' => $totalCount, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Ported from lib/data/audit-repository.ts's verifyAuditChain --
     * re-derives every row's event_hash in occurred_at order and confirms
     * both the previous_hash linkage and the hash itself still match what
     * the row claims. A genuine tamper/corruption check, not a simulated
     * one; pure read, no writes. runChainVerification() below is what
     * persists the result and raises an incident on a break.
     *
     * @return array{valid: bool, verified_count: int, first_break_id: ?string, first_break_reason: ?string}
     */
    public static function verifyChain(): array
    {
        $priorHash = null;
        $verifiedCount = 0;
        foreach (AuditEvent::orderBy('occurred_at')->orderBy('id')->cursor() as $row) {
            if (($row->previous_hash ?? null) !== $priorHash) {
                return ['valid' => false, 'verified_count' => $verifiedCount, 'first_break_id' => $row->id, 'first_break_reason' => 'PREVIOUS_HASH_MISMATCH'];
            }
            $expectedHash = hash('sha256', ($priorHash ?? 'GENESIS')."|{$row->id}|{$row->actor_id}|{$row->details}|".self::isoMicro($row->occurred_at));
            if ($expectedHash !== $row->event_hash) {
                return ['valid' => false, 'verified_count' => $verifiedCount, 'first_break_id' => $row->id, 'first_break_reason' => 'EVENT_HASH_MISMATCH'];
            }
            $priorHash = $row->event_hash;
            $verifiedCount++;
        }

        return ['valid' => true, 'verified_count' => $verifiedCount, 'first_break_id' => null, 'first_break_reason' => null];
    }

    /**
     * Ported from lib/data/audit-repository.ts's runAuditChainVerification
     * (Module 8 Phase D VerifyAuditChain -- "the chain-verification job
     * with alerting on breaks" the playbook names). This deployment has
     * no cron/queue infrastructure to run it on a schedule -- the same
     * recurring gap this migration's Reconciliation RunMatch already had
     * to document -- so it is a genuine, on-demand, actor-triggered
     * command instead, with its own result persisted as a real row (not a
     * fire-and-forget log line) so "was the chain last verified, and did
     * it pass" is itself an answerable, auditable question. A failed
     * verification opens a CRITICAL security incident through
     * App\Support\Security\SecurityEventRecorder's own detection pipeline
     * (`AUDIT_CHAIN_INTEGRITY_BREACH`, threshold 1, already seeded by
     * database/seeders/SecurityDetectionRuleSeeder -- even a single break
     * is worth an incident) -- real alerting, reusing infrastructure this
     * migration already built rather than inventing a second one.
     */
    public static function runChainVerification(User $actor, string $correlationId): AuditChainVerification
    {
        $startedAt = now();
        $result = self::verifyChain();
        $completedAt = now();

        $verification = AuditChainVerification::create([
            'id' => (string) Str::uuid(), 'requested_by' => $actor->id,
            'status' => $result['valid'] ? 'PASSED' : 'FAILED', 'verified_count' => $result['verified_count'],
            'first_break_id' => $result['first_break_id'], 'first_break_reason' => $result['first_break_reason'],
            'started_at' => $startedAt, 'completed_at' => $completedAt,
        ]);

        if (! $result['valid']) {
            SecurityEventRecorder::record(
                'AUDIT_CHAIN_BREAK', 'CRITICAL', $actor->id, 'sha256:audit-chain-verification', $correlationId,
                'VERIFY_AUDIT_CHAIN', 'FAILED',
                [
                    'verification_id' => $verification->id, 'verified_count' => $result['verified_count'],
                    'first_break_id' => $result['first_break_id'] ?? '', 'first_break_reason' => $result['first_break_reason'] ?? '',
                ],
            );
        }

        return $verification;
    }

    /** @return \Illuminate\Support\Collection<int, AuditChainVerification> */
    public static function listChainVerifications(int $limit = 50): \Illuminate\Support\Collection
    {
        $bounded = min(max($limit, 1), 200);

        return AuditChainVerification::orderByDesc('started_at')->limit($bounded)->get();
    }

    /**
     * Canonical (recursively sorted-key) JSON, matching lib/domain/invoice.ts's
     * stableStringify -- deterministic regardless of the array's construction
     * order, so the same $details always hashes identically.
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeysRecursively($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $sorted = array_map(self::sortKeysRecursively(...), $value);
        if (! $isList) {
            ksort($sorted);
        }
        return $sorted;
    }

    private static function isoMicro(\DateTimeInterface $dateTime): string
    {
        return $dateTime instanceof \DateTimeImmutable
            ? $dateTime->format('Y-m-d\TH:i:s.u\Z')
            : \DateTimeImmutable::createFromInterface($dateTime)->format('Y-m-d\TH:i:s.u\Z');
    }
}

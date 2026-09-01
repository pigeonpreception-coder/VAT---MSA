<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
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
        $occurredAt ??= now();
        $id = (string) Str::orderedUuid();

        $prior = AuditEvent::orderByDesc('id')->first();
        $body = self::canonicalJson($details);
        $hash = hash('sha256', ($prior?->event_hash ?? 'GENESIS')."|{$id}|{$actor->id}|{$body}|".self::isoMicro($occurredAt));

        return AuditEvent::create([
            'id' => $id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
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

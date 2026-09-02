<?php

namespace App\Support\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\CommandIdempotency;
use App\Models\OutboxEvent;
use App\Services\Audit\AuditService;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's shared priorCommand/
 * commandRecord/outboxRecord/validateIdempotencyKey helpers -- the generic
 * idempotency + outbox pattern every command in that file (and this phase's
 * BusinessPartyService/QuotationService) uses, reusable unchanged by later
 * Phase 10 slices (journals, expenses, inventory, projects) once they're
 * built. Audit-event writing is NOT here -- callers use
 * App\Services\Audit\AuditService::append directly (the source's own
 * auditEnvelope/auditRecord pair is just a thin wrapper around the same
 * appendAuditEvent AuditService already ports).
 */
class CommandLedger
{
    public static function validateIdempotencyKey(string $key): void
    {
        if (mb_strlen($key) < 16 || mb_strlen($key) > 128) {
            throw new BusinessResourceException('Idempotency-Key must contain 16 to 128 characters.', 422);
        }
    }

    public static function requestHash(array $payload): string
    {
        return hash('sha256', AuditService::canonicalJson($payload));
    }

    /**
     * Returns the prior resource_id for a replayed key, or null if this is
     * a genuinely new command. Throws RepositoryConflictException if the
     * same key was already used for a different payload.
     */
    public static function prior(string $actorId, string $commandType, string $idempotencyKey, string $requestHash): ?string
    {
        $prior = CommandIdempotency::where('actor_id', $actorId)
            ->where('command_type', $commandType)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if (! $prior) {
            return null;
        }
        if ($prior->request_hash !== $requestHash) {
            throw new RepositoryConflictException('The idempotency key was already used for a different command payload.');
        }

        return $prior->resource_id;
    }

    public static function record(string $actorId, string $commandType, string $idempotencyKey, string $requestHash, string $resourceType, string $resourceId, \DateTimeInterface $now): void
    {
        CommandIdempotency::create([
            'id' => (string) Str::uuid(), 'actor_id' => $actorId, 'command_type' => $commandType,
            'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
            'resource_type' => $resourceType, 'resource_id' => $resourceId, 'created_at' => $now,
        ]);
    }

    public static function outbox(string $aggregateType, string $aggregateId, string $eventType, string $partitionKey, array $payload, \DateTimeInterface $now): void
    {
        OutboxEvent::create([
            'id' => (string) Str::uuid(), 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId,
            'event_type' => $eventType, 'event_version' => 1, 'partition_key' => $partitionKey,
            'payload' => AuditService::canonicalJson($payload), 'status' => 'PENDING', 'publish_attempts' => 0,
            'occurred_at' => $now, 'available_at' => $now,
        ]);
    }
}

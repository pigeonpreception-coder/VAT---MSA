<?php

namespace App\Services\Platform;

use App\Domain\Platform\OfflineSyncValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/platform-repository.ts's receiveOfflineBatch --
 * Module 22's offline-invoicing sync-batch intake, the third slice of
 * Phase 13 after "Document module" and "Platform snapshots". Still NOT
 * STARTED from that same source file: report exports, data products/
 * analytics, platform config/change-management -- each its own
 * comparable sub-module, deliberately left out of this slice.
 *
 * No Eloquent model for `offline_devices`/`offline_sync_batches` yet --
 * `DB::table()` throughout, matching this phase's own established style
 * (see PlatformSnapshotService's doc comment for why).
 *
 * The source's own `enforceRateLimits`/`readBoundedJson`/
 * `emitStructuredSecurityLog` calls around `handleOfflineBatch` are NOT
 * ported here -- the same orthogonal-concern deferral
 * App\Http\Controllers\Document\DocumentController's doc comment already
 * documents for rate limiting, extended here to the request-body-size
 * bound and structured security logging this migration has not built a
 * home for anywhere yet either.
 *
 * Faithful-port note: the source has never actually wired up real
 * device-signature verification. `$rejection` starts as
 * "SIGNATURE_VERIFIER_NOT_CONFIGURED" and is only ever overridden by the
 * device-trust/sequence/hash-chain checks below it -- so a batch that
 * passes all three of those still falls through to that same default and
 * is written with `status='REJECTED'` regardless; there is no path in the
 * source that ever accepts a batch. Reproduced exactly as the source has
 * it (this migration's "reproduce source quirks faithfully" convention),
 * not "fixed" by inventing a signature verifier the source itself never
 * built.
 */
class OfflineSyncService
{
    /** @return array<string, mixed> */
    public function receive(array $payload, User $actor, string $correlationId): array
    {
        $batch = OfflineSyncValidator::batch($payload);

        $device = DB::table('offline_devices as d')
            ->join('organisations as o', 'o.id', '=', 'd.organisation_id')
            ->where(fn ($q) => $q->where('d.id', $batch['device_id'])->orWhere('d.device_code', $batch['device_id']))
            ->select('d.*', 'o.taxpayer_id')
            ->first();
        if (! $device) {
            throw new PlatformResourceException('Offline device is not enrolled.', 404);
        }
        if (! TenantScope::isNational($actor) && $actor->taxpayer_id !== $device->taxpayer_id) {
            throw new AuthorizationException('The offline device is outside your authorised scope.');
        }

        $batchHash = hash('sha256', AuditService::canonicalJson([
            'device_id' => $batch['device_id'],
            'batch_id' => $batch['batch_id'],
            'sequence_from' => $batch['sequence_from'],
            'sequence_to' => $batch['sequence_to'],
            'created_at' => $batch['created_at'],
            'previous_batch_hash' => $batch['previous_batch_hash'],
            'documents' => $batch['documents'],
        ]));

        $prior = DB::table('offline_sync_batches')
            ->where('offline_device_id', $device->id)->where('client_batch_id', $batch['batch_id'])->first();
        if ($prior) {
            if ($prior->batch_hash !== $batchHash) {
                throw new RepositoryConflictException('Offline batch id was reused with different content.');
            }

            return (array) $prior;
        }

        $rejection = 'SIGNATURE_VERIFIER_NOT_CONFIGURED';
        if ($device->status !== 'ACTIVE' || $device->enrolment_status !== 'VERIFIED' || ! $device->public_key_reference) {
            $rejection = 'DEVICE_TRUST_NOT_ESTABLISHED';
        } elseif ($batch['sequence_from'] !== (int) $device->last_accepted_sequence + 1) {
            $rejection = 'SEQUENCE_GAP_OR_REPLAY';
        } elseif (($device->last_batch_hash ?? null) !== $batch['previous_batch_hash']) {
            $rejection = 'HASH_CHAIN_MISMATCH';
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($id, $device, $batch, $batchHash, $rejection, $now, $actor, $correlationId) {
            DB::table('offline_sync_batches')->insert([
                'id' => $id, 'offline_device_id' => $device->id, 'client_batch_id' => $batch['batch_id'],
                'sequence_from' => $batch['sequence_from'], 'sequence_to' => $batch['sequence_to'],
                'previous_batch_hash' => $batch['previous_batch_hash'], 'batch_hash' => $batchHash,
                'signature' => $batch['device_signature'], 'document_count' => count($batch['documents']),
                'status' => 'REJECTED', 'received_at' => $now, 'processed_at' => $now, 'rejection_reason' => $rejection,
            ]);
            CommandLedger::outbox('OFFLINE_BATCH', $id, 'OfflineBatchRejected', $device->taxpayer_id, [
                'batch_id' => $id, 'device_id' => $device->id, 'reason' => $rejection, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'OFFLINE_BATCH_REJECTED', 'OFFLINE_BATCH', $id, [
                'deviceId' => $device->id, 'rejection' => $rejection, 'correlationId' => $correlationId,
            ], $now);
        });

        return (array) DB::table('offline_sync_batches')->where('id', $id)->first();
    }
}

<?php

namespace App\Services\Identity;

use App\Models\OutboxEvent;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ported from lib/data/identity-repository.ts's suspendTaxpayer. Flips
 * taxpayers.vat_status to SUSPENDED -- Taxpayer::isActive() and every
 * counterparty-resolution query elsewhere must filter on vat_status='ACTIVE'
 * for this to have real enforcement effect, matching the source's own note.
 * Idempotent: suspending an already-suspended taxpayer is a no-op.
 */
class TaxpayerService
{
    /** @return array{taxpayerId: string, vatStatus: string} */
    public function suspend(User $actor, string $taxpayerId, string $reason, string $correlationId): array
    {
        $taxpayer = Taxpayer::find($taxpayerId);
        if (! $taxpayer) {
            throw ValidationException::withMessages(['taxpayer_id' => 'The taxpayer does not exist.']);
        }
        if ($taxpayer->vat_status === 'SUSPENDED') {
            return ['taxpayerId' => $taxpayer->id, 'vatStatus' => 'SUSPENDED'];
        }

        $now = now();
        $previousStatus = $taxpayer->vat_status;

        DB::transaction(function () use ($taxpayer, $reason, $actor, $now, $correlationId, $previousStatus) {
            $taxpayer->update(['vat_status' => 'SUSPENDED']);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'TAXPAYER', 'aggregate_id' => $taxpayer->id,
                'event_type' => 'TaxpayerSuspended', 'event_version' => 1, 'partition_key' => $taxpayer->id,
                'payload' => AuditService::canonicalJson(['taxpayer_id' => $taxpayer->id, 'reason' => $reason, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'TAXPAYER_SUSPENDED', 'TAXPAYER', $taxpayer->id, ['reason' => $reason, 'previousStatus' => $previousStatus], $now);
        });

        return ['taxpayerId' => $taxpayerId, 'vatStatus' => 'SUSPENDED'];
    }
}

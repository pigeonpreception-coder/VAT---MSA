<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `audit_chain_verifications` table (Module 8 Phase D) -- the run log
 * a not-yet-ported VerifyAuditChain command would write, walking
 * `audit_events`' own hash chain. First real write path:
 * App\Services\Audit\AuditService::runChainVerification().
 */
class AuditChainVerification extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

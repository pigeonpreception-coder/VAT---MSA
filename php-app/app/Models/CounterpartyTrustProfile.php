<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from db/runtime.ts's `counterparty_trust_profiles` table -- see
 * that migration's own doc comment. Deliberately does NOT use
 * App\Models\Concerns\BelongsToOrganisation: this table has no
 * `organisation_id` column of its own (same as the source), it scopes
 * through its `party()` relation to App\Models\BusinessParty, which is
 * itself organisation-scoped. Every caller in this codebase reaches a
 * trust profile only via an already organisation-scoped BusinessParty
 * lookup (App\Support\Business\CounterpartyTrustGate,
 * App\Services\Business\CounterpartyTrustService), never directly by id.
 */
class CounterpartyTrustProfile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'confidence_bps' => 'integer', 'checked_at' => 'datetime', 'expires_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(BusinessParty::class, 'business_party_id');
    }

    public function isCurrent(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}

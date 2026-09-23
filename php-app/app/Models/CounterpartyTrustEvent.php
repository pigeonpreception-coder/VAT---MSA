<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from db/runtime.ts's `counterparty_trust_events` table -- see
 * that migration's own doc comment. Not organisation-scoped directly, same
 * reasoning as its sibling trust-family models.
 */
class CounterpartyTrustEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];

    public function trustProfile(): BelongsTo
    {
        return $this->belongsTo(CounterpartyTrustProfile::class, 'trust_profile_id');
    }
}

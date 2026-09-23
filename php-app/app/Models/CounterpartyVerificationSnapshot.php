<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from db/runtime.ts's `counterparty_verification_snapshots` table
 * -- see that migration's own doc comment. Not organisation-scoped
 * directly, same reasoning as App\Models\CounterpartyTrustProfile: it
 * scopes through `trustProfile()` -> `party()` to an organisation-scoped
 * business_parties row.
 */
class CounterpartyVerificationSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'confidence_bps' => 'integer', 'checked_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function trustProfile(): BelongsTo
    {
        return $this->belongsTo(CounterpartyTrustProfile::class, 'trust_profile_id');
    }
}

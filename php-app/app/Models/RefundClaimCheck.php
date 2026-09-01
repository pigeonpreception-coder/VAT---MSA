<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundClaimCheck extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['evaluated_at' => 'datetime'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(RefundClaim::class, 'refund_claim_id');
    }
}

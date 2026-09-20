<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from db/runtime.ts's `payment_instructions` table -- see that table's own migration comment. */
class PaymentInstruction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'submitted_at' => 'datetime', 'settled_at' => 'datetime'];

    public function refundClaim(): BelongsTo
    {
        return $this->belongsTo(RefundClaim::class);
    }
}

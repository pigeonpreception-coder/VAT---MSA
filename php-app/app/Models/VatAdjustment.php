<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatAdjustment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'approved_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(VatPeriod::class, 'vat_period_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefundClaim extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['requested_at' => 'datetime', 'approved_at' => 'datetime'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(VatReturnVersion::class, 'vat_return_version_id');
    }

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(RefundClaimTransition::class)->orderBy('occurred_at');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(RefundClaimCheck::class)->orderBy('evaluated_at');
    }
}

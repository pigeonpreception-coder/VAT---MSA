<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VatReturnVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime', 'approved_at' => 'datetime', 'superseded_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(VatPeriod::class, 'vat_period_id');
    }

    public function taxRuleSet(): BelongsTo
    {
        return $this->belongsTo(TaxRuleSet::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(VatReturnBox::class)->orderBy('box_code');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(VatReturnSubmission::class);
    }
}

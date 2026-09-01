<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}

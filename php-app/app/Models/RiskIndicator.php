<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskIndicator extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['detected_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    public function escalatedCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class, 'escalated_case_id');
    }
}

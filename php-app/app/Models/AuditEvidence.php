<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditEvidence extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['added_at' => 'datetime', 'legal_hold' => 'boolean'];

    public function auditCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class);
    }

    public function custodyEvents(): HasMany
    {
        return $this->hasMany(AuditEvidenceCustodyEvent::class);
    }
}

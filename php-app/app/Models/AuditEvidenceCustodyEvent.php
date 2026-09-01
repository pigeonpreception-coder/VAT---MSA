<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvidenceCustodyEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'integrity_verified' => 'boolean'];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(AuditEvidence::class, 'audit_evidence_id');
    }
}

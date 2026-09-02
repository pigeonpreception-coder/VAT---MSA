<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditCase extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['opened_at' => 'datetime', 'updated_at' => 'datetime', 'closed_at' => 'datetime', 'appeal_linked_at' => 'datetime'];

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(AuditCaseTransition::class)->orderBy('occurred_at');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AuditEvidence::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(AuditCaseNote::class);
    }
}

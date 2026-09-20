<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityIncident extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['opened_at' => 'datetime', 'updated_at' => 'datetime', 'closed_at' => 'datetime'];

    public function playbookActions(): HasMany
    {
        return $this->hasMany(SecurityPlaybookAction::class, 'incident_id');
    }

    public function detectionRule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SecurityDetectionRule::class, 'detection_rule_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ported from db/runtime.ts's `saas_applications` table -- see its own migration comment. */
class SaasApplication extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SaasProvider::class, 'saas_provider_id');
    }

    public function conformanceRuns(): HasMany
    {
        return $this->hasMany(SaasConformanceRun::class);
    }

    public function environmentApprovals(): HasMany
    {
        return $this->hasMany(SaasEnvironmentApproval::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from db/runtime.ts's `saas_conformance_runs` table -- see its own migration comment. */
class SaasConformanceRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['submitted_at' => 'datetime'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(SaasApplication::class, 'saas_application_id');
    }
}

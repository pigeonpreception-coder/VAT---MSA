<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from db/runtime.ts's `saas_environment_approvals` table -- see its own migration comment. */
class SaasEnvironmentApproval extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['updated_at' => 'datetime'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(SaasApplication::class, 'saas_application_id');
    }

    public function conformanceRun(): BelongsTo
    {
        return $this->belongsTo(SaasConformanceRun::class, 'conformance_run_id');
    }
}

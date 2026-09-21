<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from db/runtime.ts's `self_serve_signup_applications` table -- see its own migration comment. */
class SelfServeSignupApplication extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'authority_attested_at' => 'datetime', 'terms_accepted_at' => 'datetime',
        'privacy_notice_accepted_at' => 'datetime', 'submitted_at' => 'datetime',
    ];

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'requested_plan_id');
    }
}

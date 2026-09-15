<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ported from db/runtime.ts's `step_up_events` table -- the server-side log
 * a verified TOTP challenge writes, checked by App\Services\Identity\
 * MfaService::hasFreshStepUp (the real replacement for the source's own
 * requireStepUp).
 */
class StepUpEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}

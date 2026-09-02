<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Ported from db/runtime.ts's `audit_events` table. Written only through App\Services\Audit\AuditService -- never insert directly, the hash chain must stay linear. */
class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    // `details` is deliberately NOT cast to 'array' -- it must stay exactly
    // the canonical JSON string App\Services\Audit\AuditService hashed at
    // write time (Eloquent's array cast would re-encode it with PHP's own
    // key ordering on save, silently diverging from the hash). Use
    // AuditService::decodeDetails($event->details) to read it back typed.
}

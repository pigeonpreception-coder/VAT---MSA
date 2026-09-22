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

    // Eloquent's default MySQL date format ('Y-m-d H:i:s') truncates to
    // whole seconds on save regardless of the column's own precision --
    // widened to TIMESTAMP(6) by the 2026-09-22 migration specifically so
    // this model's own microsecond-precision writes round-trip exactly,
    // matching what App\Services\Audit\AuditService::write() hashes. See
    // that migration's own doc comment for the discovered defect this
    // fixes and its disclosed, unrecoverable effect on rows written
    // before it.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    // `details` is deliberately NOT cast to 'array' -- it must stay exactly
    // the canonical JSON string App\Services\Audit\AuditService hashed at
    // write time (Eloquent's array cast would re-encode it with PHP's own
    // key ordering on save, silently diverging from the hash). Use
    // AuditService::decodeDetails($event->details) to read it back typed.
}

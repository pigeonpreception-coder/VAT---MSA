<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ported from db/runtime.ts's `service_components` table -- the fixed
 * component inventory PlatformSnapshotService already reads for display.
 * App\Integrations\Payment\SandboxPaymentConnector is this table's first
 * genuine runtime *enforcement* consumer (component_key='PAYMENT_CONNECTOR'),
 * not just a display row -- see that class's own doc comment.
 */
class ServiceComponent extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['last_checked_at' => 'datetime'];
}

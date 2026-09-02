<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** feature_key is its own natural primary key (a fixed catalogue code, e.g. CORE_VAT), not a UUID -- matches db/runtime.ts's schema exactly. */
class LicenseFeature extends Model
{
    protected $primaryKey = 'feature_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['protected' => 'boolean', 'created_at' => 'datetime'];
}

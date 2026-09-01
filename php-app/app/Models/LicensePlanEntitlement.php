<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicensePlanEntitlement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(LicenseFeature::class, 'feature_key', 'feature_key');
    }
}

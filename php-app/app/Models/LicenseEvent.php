<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];

    public function license(): BelongsTo
    {
        return $this->belongsTo(OrganisationLicense::class, 'organisation_license_id');
    }
}

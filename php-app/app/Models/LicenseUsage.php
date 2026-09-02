<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseUsage extends Model
{
    use BelongsToOrganisation, HasUuids;

    // db/runtime.ts's table is singular (`license_usage`, not the
    // pluralised `license_usages` Eloquent would infer from the class name).
    protected $table = 'license_usage';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['updated_at' => 'datetime'];

    public function license(): BelongsTo
    {
        return $this->belongsTo(OrganisationLicense::class, 'organisation_license_id');
    }
}

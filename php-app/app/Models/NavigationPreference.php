<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `organisation_id` is schema-nullable, but this table's one and only
 * usage (`NavigationService::saveNavigationPreference`) always resolves a
 * real organisation via `LicenseResolver::resolveOrganisation` before its
 * `updateOrCreate` -- no NULL-organisation row is ever written or read by
 * this migration, so the organisation-scope trait's own redundant filter
 * is safe here, unlike `CommunicationThread`/`Communication` (see
 * docs/MIGRATION_MATRIX.md's "Organisation-scope trait: the
 * nullable-column exclusions" section).
 */
class NavigationPreference extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}

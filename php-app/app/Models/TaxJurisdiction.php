<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `id` holds the source's own stable, human-readable seed IDs (e.g.
 * 'tax-jurisdiction-na-national'), not generated UUIDs -- no command in
 * this module creates a tax_jurisdictions row, matching TaxAuthority's own
 * doc comment for the same reason -- including its `$incrementing`/
 * `$keyType` overrides, for the same implicit-int-cast reason explained
 * there.
 */
class TaxJurisdiction extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function taxAuthorities(): HasMany
    {
        return $this->hasMany(TaxAuthority::class, 'jurisdiction_id');
    }
}

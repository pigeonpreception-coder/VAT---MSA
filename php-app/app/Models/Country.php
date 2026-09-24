<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `code` (ISO 3166-1 alpha-2, e.g. 'NA') is the primary key and holds the
 * source's own plain string seed values, not generated UUIDs -- no command
 * in this module creates a countries row, matching TaxAuthority's own doc
 * comment for the same reason.
 */
class Country extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public function jurisdictions(): HasMany
    {
        return $this->hasMany(TaxJurisdiction::class, 'country_code', 'code');
    }
}

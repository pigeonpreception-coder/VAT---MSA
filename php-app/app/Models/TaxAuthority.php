<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `id` holds the source's own stable, human-readable seed IDs (e.g.
 * 'tax-authority-na-namra'), not generated UUIDs -- no command in this
 * module creates a tax_authorities row, so `HasUuids` (used by every
 * sibling model below that a command does create) is deliberately
 * omitted here.
 *
 * `$incrementing`/`$keyType` are set explicitly (2026-09-24, found while
 * building phase 3's TaxJurisdiction/Country lookups): Eloquent's own
 * Model::getCasts() implicitly adds `[$this->getKeyName() => $this->
 * getKeyType()]` whenever `$incrementing` is true, which it is by
 * default -- so without this, every read of `id` (not just find()/
 * where() results but this model's own `organisations()` hasMany, which
 * keys its query off `$this->getAttribute('id')`) got silently cast to
 * `(int) 'tax-authority-na-namra'` = 0. That masked itself in phase 2's
 * own tests only because MySQL's loose string-to-number comparison
 * coerces every non-numeric tax_authority_id value to 0 too, so `WHERE
 * tax_authority_id = 0` happened to match every row instead of none --
 * an accidental over-broad match, not a real pass.
 */
class TaxAuthority extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Multi-tenant SaaS pivot phase 2 (2026-09-24) -- see Organisation::taxAuthority()'s own doc comment. */
    public function organisations(): HasMany
    {
        return $this->hasMany(Organisation::class);
    }

    /** Multi-tenant SaaS pivot phase 3 (2026-09-24) -- see Organisation::jurisdictionCountryCode()'s own doc comment. */
    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(TaxJurisdiction::class, 'jurisdiction_id');
    }
}

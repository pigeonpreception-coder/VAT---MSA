<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `id` holds the source's own stable, human-readable seed IDs (e.g.
 * 'tax-authority-na-namra'), not generated UUIDs -- no command in this
 * module creates a tax_authorities row, so `HasUuids` (used by every
 * sibling model below that a command does create) is deliberately
 * omitted here.
 */
class TaxAuthority extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** Multi-tenant SaaS pivot phase 2 (2026-09-24) -- see Organisation::taxAuthority()'s own doc comment. */
    public function organisations(): HasMany
    {
        return $this->hasMany(Organisation::class);
    }
}
